<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Runtime\Messaging;
use App\Runtime\OutboxRelay;
use Illuminate\Console\Command;

/** php artisan outbox:relay — publishes the outbox to RabbitMQ every 1000 ms (DATABASE_URL, AMQP_URL). */
final class OutboxRelayCommand extends Command
{
    protected $signature = 'outbox:relay';
    protected $description = 'Publish the transactional outbox to RabbitMQ until stopped.';

    public function handle(): int
    {
        $connection = Messaging::connect((string) env('AMQP_URL'));
        $channel = $connection->channel();
        $topology = Messaging::forPrefix();
        $topology->declare($channel);
        $relay = new OutboxRelay((string) env('DATABASE_URL'), $channel, $topology->exchange);
        $workerId = 'checkout-autenticado-con-laravel-sanctum-e-inertia-vue-' . bin2hex(random_bytes(6));
        while (true) {
            $relay->publishBatch($workerId);
            usleep(1000000);
        }
    }
}
