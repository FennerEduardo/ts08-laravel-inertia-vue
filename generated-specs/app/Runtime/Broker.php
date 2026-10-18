<?php

declare(strict_types=1);

namespace App\Runtime;

interface Broker
{
    public function system(): string;

    /** Creates the topology if missing (idempotent); in production it usually comes from infrastructure as code. */
    public function declare(Messaging $topology): void;

    public function publisher(string $destination): EventPublisher;

    /** $topology says where dead-lettered messages go. */
    public function subscribe(string $source, Messaging $topology): EventSubscription;

    public function close(): void;
}
