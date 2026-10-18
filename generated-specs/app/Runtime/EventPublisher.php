<?php

declare(strict_types=1);

namespace App\Runtime;

/** Publishes to one destination; publish() returns only once the broker has durably accepted the message. */
interface EventPublisher
{
    public function system(): string;

    public function destination(): string;

    public function publish(BrokerMessage $message): void;

    public function close(): void;
}
