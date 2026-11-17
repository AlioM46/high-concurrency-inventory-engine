<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Models\Organization;
use App\Models\ProductWarehouse;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Validate Idempotency-Key header existence
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! $idempotencyKey) {
            return response()->json([
                'success' => false,
                'error_code' => 'MISSING_IDEMPOTENCY_KEY',
                'message' => 'The Idempotency-Key header is required for this endpoint.',
            ], 400);
        }

        $idempotencyKey = trim($idempotencyKey);

        // 2. Validate Idempotency-Key format (must be valid UUID)
        if (! Str::isUuid($idempotencyKey)) {
            return response()->json([
                'success' => false,
                'error_code' => 'INVALID_IDEMPOTENCY_KEY',
                'message' => 'The Idempotency-Key header must be a valid UUID.',
            ], 400);
        }

        // 3. Compute deterministic request hash
        $requestHash = $this->computeRequestHash($request);

        // 4. Check for existing idempotency record in DB
        $existingRecord = IdempotencyKey::where('idempotency_key', $idempotencyKey)->first();

        if ($existingRecord) {
            if ($existingRecord->expires_at && $existingRecord->expires_at->isPast()) {
                // Key expired: remove stale record to allow fresh execution
                $existingRecord->delete();
                $existingRecord = null;
            } elseif ($existingRecord->request_hash !== $requestHash) {
                // Key reused with a different request payload or endpoint -> Conflict
                return response()->json([
                    'success' => false,
                    'error_code' => 'IDEMPOTENCY_PAYLOAD_MISMATCH',
                    'message' => 'The provided Idempotency-Key has already been used with a different request payload or endpoint.',
                ], 409);
            } elseif ($existingRecord->response_status !== null) {
                // Completed request -> replay cached response immediately
                return response()->json(
                    $existingRecord->response_body,
                    $existingRecord->response_status,
                    [
                        'X-Idempotent-Replay' => 'true',
                        'X-Original-Timestamp' => $existingRecord->created_at?->toIso8601String(),
                    ]
                );
            } else {
                // Request is currently in-flight from another concurrent process
                return response()->json([
                    'success' => false,
                    'error_code' => 'IDEMPOTENT_REQUEST_IN_FLIGHT',
                    'message' => 'A request with this Idempotency-Key is currently being processed. Please retry shortly.',
                ], 409);
            }
        }

        // 5. Distributed Concurrency Lock via Redis (prevent microsecond race conditions)
        $lockKey = "idempotency_lock:{$idempotencyKey}";
        $lock = Cache::lock($lockKey, 30);

        if (! $lock->get()) {
            return response()->json([
                'success' => false,
                'error_code' => 'IDEMPOTENT_REQUEST_IN_FLIGHT',
                'message' => 'A request with this Idempotency-Key is currently being processed. Please retry shortly.',
            ], 409);
        }

        // 6. Insert in-flight record into DB
        $organizationId = $this->resolveOrganizationId($request);
        $record = null;

        try {
            $record = IdempotencyKey::create([
                'organization_id' => $organizationId,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'endpoint' => $request->path(),
                'response_status' => null,
                'response_body' => null,
                'expires_at' => now()->addHours(24),
            ]);
        } catch (QueryException $e) {
            // In case of a rare race condition where another worker inserted the row simultaneously
            optional($lock)->release();

            return response()->json([
                'success' => false,
                'error_code' => 'IDEMPOTENT_REQUEST_IN_FLIGHT',
                'message' => 'A request with this Idempotency-Key is currently being processed. Please retry shortly.',
            ], 409);
        }

        // 7. Execute controller pipeline
        try {
            $response = $next($request);

            // Cache response if not a 5xx server error
            if ($response->getStatusCode() < 500) {
                $content = $response->getContent();
                $decoded = json_decode($content, true);
                $body = is_array($decoded) ? $decoded : ['raw' => $content];

                $record->update([
                    'response_status' => $response->getStatusCode(),
                    'response_body' => $body,
                    'expires_at' => now()->addHours(24),
                ]);

                $response->headers->set('X-Idempotent-Replay', 'false');
            } else {
                // Server error: remove record so client can safely retry once server recovers
                $record->delete();
            }

            return $response;
        } catch (\Throwable $e) {
            // Exception thrown: delete in-flight record so retry is permitted
            $record?->delete();
            throw $e;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Compute a deterministic SHA-256 hash of the request.
     */
    protected function computeRequestHash(Request $request): string
    {
        $payload = $request->all();
        $this->sortPayloadRecursively($payload);

        $canonicalString = implode(':', [
            strtoupper($request->getMethod()),
            $request->path(),
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        return hash('sha256', $canonicalString);
    }

    /**
     * Sort payload keys recursively for canonical JSON representation.
     */
    protected function sortPayloadRecursively(array &$data): void
    {
        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->sortPayloadRecursively($value);
            }
        }
        ksort($data);
    }

    /**
     * Resolve the organization ID for tenant binding.
     */
    protected function resolveOrganizationId(Request $request): string
    {
        $orgId = $request->header('X-Organization-Id')
            ?? $request->input('organization_id')
            ?? $request->user()?->organization_id;

        if ($orgId && Organization::where('id', $orgId)->exists()) {
            return $orgId;
        }

        // Check if first item product warehouse provides organization
        $firstPwId = $request->input('items.0.product_warehouse_id');
        if ($firstPwId) {
            $pwOrgId = ProductWarehouse::where('id', $firstPwId)->value('organization_id');
            if ($pwOrgId) {
                return $pwOrgId;
            }
        }

        // Fallback to existing or default organization
        $org = Organization::first();
        if ($org) {
            return $org->id;
        }

        $defaultOrg = Organization::create([
            'name' => 'Default Organization',
            'slug' => 'default-org',
            'is_active' => true,
        ]);

        return $defaultOrg->id;
    }
}
