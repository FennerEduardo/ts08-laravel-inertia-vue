<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\CommandPort;
use App\Application\RuntimeCommandPort;
use App\Runtime\Schema;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Contrib\Otlp\SpanExporterFactory;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

final class RuntimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CommandPort::class, fn () => new RuntimeCommandPort((string) env('DATABASE_URL', '')));
    }

    /**
     * OpenTelemetry export of the running service: with OTEL_EXPORTER_OTLP_ENDPOINT set, spans go over OTLP/HTTP to
     * that collector (service.name from OTEL_SERVICE_NAME); without it nothing is exported and nothing connects.
     */
    public function boot(): void
    {
        self::migrateRuntimeSchema();
        $endpoint = getenv('OTEL_EXPORTER_OTLP_ENDPOINT');
        if ($endpoint === false || $endpoint === '' || $this->app->runningUnitTests()) {
            return;
        }
        $resource = ResourceInfoFactory::defaultResource()->merge(ResourceInfo::create(Attributes::create([
            'service.name' => getenv('OTEL_SERVICE_NAME') ?: 'laravel-inertia-vue-auth',
        ])));
        $tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(BatchSpanProcessor::builder((new SpanExporterFactory())->create())->build())
            ->setResource($resource)
            ->build();
        Sdk::builder()
            ->setTracerProvider($tracerProvider)
            ->setPropagator(TraceContextPropagator::getInstance())
            ->setAutoShutdown(true)
            ->buildAndRegisterGlobal();
    }

    /**
     * Creates the kernel's tables before the first command, once per container: PHP requests share no memory,
     * so a marker in storage/framework records it (the kernel serializes replicas with an advisory lock).
     * GHK_MIGRATE=false when a migration job owns the schema. The test suites migrate their own schemas.
     */
    public static function migrateRuntimeSchema(): void
    {
        $url = (string) env('DATABASE_URL', '');
        $enabled = env('GHK_MIGRATE', true);
        if ($url === '' || $enabled === false || $enabled === 'false' || app()->runningUnitTests()) {
            return;
        }
        $marker = storage_path('framework/ghk-runtime-schema');
        if (is_file($marker)) {
            return;
        }
        $lock = fopen($marker . '.lock', 'c');
        try {
            flock($lock, LOCK_EX);
            if (!is_file($marker)) {
                Schema::migrate($url);
                touch($marker);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
