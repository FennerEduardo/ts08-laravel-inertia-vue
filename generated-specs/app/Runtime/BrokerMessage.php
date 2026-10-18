<?php

declare(strict_types=1);

namespace App\Runtime;

/** A domain event on the wire, independent of the broker. */
final class BrokerMessage
{
    public function __construct(
        public readonly string $messageId,
        public readonly ?string $eventType,
        public readonly ?string $tenantId,
        public readonly ?string $traceparent,
        public readonly string $body,
    ) {
    }
}
