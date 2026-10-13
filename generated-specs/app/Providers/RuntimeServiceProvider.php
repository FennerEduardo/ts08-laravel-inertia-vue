<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\CommandPort;
use App\Application\RuntimeCommandPort;
use Illuminate\Support\ServiceProvider;

final class RuntimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CommandPort::class, fn () => new RuntimeCommandPort((string) env('DATABASE_URL', '')));
    }
}
