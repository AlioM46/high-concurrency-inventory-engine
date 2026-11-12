<?php

namespace Tests\Feature;

use App\Models\ProductWarehouse;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class DatabaseConstraintTest extends TestCase
{
    public function test_health_check_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('services.database.status', 'connected')
            ->assertJsonPath('services.cache_and_queues.status', 'connected');
    }

    public function test_database_constraint_blocks_negative_available_quantity(): void
    {
        $this->expectException(QueryException::class);

        $pw = ProductWarehouse::first();
        $this->assertNotNull($pw, 'Seed data should exist');

        // Attempting to set available_quantity to -1 must be blocked by PostgreSQL CHECK constraint
        $pw->update(['available_quantity' => -1]);
    }
}
