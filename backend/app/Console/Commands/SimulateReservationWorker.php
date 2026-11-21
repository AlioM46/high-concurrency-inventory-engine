<?php

namespace App\Console\Commands;

use App\Exceptions\InsufficientStockException;
use App\Services\AtomicUpdateReservationService;
use App\Services\PessimisticLockReservationService;
use Illuminate\Console\Command;

class SimulateReservationWorker extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'stockforge:reserve-worker
                            {--strategy=atomic : Reservation strategy (pessimistic|atomic)}
                            {--customer=cust_worker : Customer ID}
                            {--payload= : JSON payload containing items array}';

    /**
     * The console command description.
     */
    protected $description = 'Worker command to execute a single reservation under multi-process concurrency testing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $strategy = $this->option('strategy');
        $customer = $this->option('customer');
        $payloadRaw = $this->option('payload');

        $items = json_decode($payloadRaw, true);
        if (! is_array($items)) {
            $this->output->write(json_encode([
                'success' => false,
                'error' => 'INVALID_PAYLOAD',
            ]));
            return 1;
        }

        $service = $strategy === 'pessimistic'
            ? app(PessimisticLockReservationService::class)
            : app(AtomicUpdateReservationService::class);

        try {
            $reservation = $service->create([
                'customer_id' => $customer,
                'items' => $items,
                'expires_in_minutes' => 10,
            ]);

            $this->output->write(json_encode([
                'success' => true,
                'reservation_id' => $reservation->id,
            ]));

            return 0;
        } catch (InsufficientStockException $e) {
            $this->output->write(json_encode([
                'success' => false,
                'error' => 'INSUFFICIENT_STOCK',
                'message' => $e->getMessage(),
            ]));

            return 2;
        } catch (\Throwable $e) {
            $this->output->write(json_encode([
                'success' => false,
                'error' => get_class($e),
                'message' => $e->getMessage(),
            ]));

            return 1;
        }
    }
}
