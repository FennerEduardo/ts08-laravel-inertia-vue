<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Runtime\Brokers;
use App\Runtime\Messaging;
use App\Runtime\OutboxRelay;
use Illuminate\Console\Command;

/**
 * php artisan outbox:relay — publishes the outbox every 1000 ms to the configured broker
 * (DATABASE_URL; GHK_BROKER with AMQP_URL or PUBSUB_PROJECT_ID, see App\Runtime\Brokers).
 */
final class OutboxRelayCommand extends Command
{
    protected $signature = 'outbox:relay';
    protected $description = 'Publish the transactional outbox to the event broker until stopped.';

    public function handle(): int
    {
        $broker = Brokers::connect();
        $topology = Messaging::forPrefix();
        $broker->declare($topology);
        $relay = new OutboxRelay((string) env('DATABASE_URL'), $broker->publisher($topology->exchange));
        $workerId = 'checkout-autenticado-con-laravel-sanctum-e-inertia-vue-' . bin2hex(random_bytes(6));
        while (true) {
            $relay->publishBatch($workerId);
            usleep(1000000);
        }
    }
}
