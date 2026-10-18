<?php

declare(strict_types=1);

namespace App\Runtime;

/** Pulls messages from one queue / subscription. */
interface EventSubscription
{
    public function system(): string;

    public function source(): string;

    /** The next waiting message, or null when there is none. */
    public function poll(): ?Delivery;

    public function close(): void;
}
