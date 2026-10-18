<?php

declare(strict_types=1);

namespace App\Runtime;

/** A received message: ack() once processed; deadLetter() moves it to the DLQ (it is not redelivered). */
final class Delivery
{
    public function __construct(public readonly BrokerMessage $message, private readonly \Closure $onAck, private readonly \Closure $onDeadLetter)
    {
    }

    public function ack(): void
    {
        ($this->onAck)();
    }

    public function deadLetter(): void
    {
        ($this->onDeadLetter)();
    }
}
