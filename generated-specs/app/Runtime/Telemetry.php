<?php

declare(strict_types=1);

namespace App\Runtime;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;

/** W3C trace-context helpers; spans go to the global tracer provider (configure exporters with OTEL_*). */
final class Telemetry
{
    public static function tracer(): TracerInterface
    {
        return Globals::tracerProvider()->getTracer('checkout-autenticado-con-laravel-sanctum-e-inertia-vue');
    }

    /** Context whose parent is the span described by an incoming traceparent header. */
    public static function contextFrom(?string $traceparent): ContextInterface
    {
        if ($traceparent === null || $traceparent === '') {
            return Context::getCurrent();
        }
        return TraceContextPropagator::getInstance()->extract(['traceparent' => $traceparent]);
    }

    /** traceparent header value for a span (null when the span is invalid). */
    public static function traceparentOf(SpanInterface $span, ContextInterface $parent): ?string
    {
        $carrier = [];
        TraceContextPropagator::getInstance()->inject($carrier, null, $span->storeInContext($parent));
        return $carrier['traceparent'] ?? null;
    }
}
