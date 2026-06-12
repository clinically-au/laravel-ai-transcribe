<?php

declare(strict_types=1);

namespace Clinically\AiTranscribe;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Ai;

final class AiTranscribeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! class_exists(Ai::class)) {
            return;
        }

        Ai::extend('aws-transcribe', fn (Application $app, array $config) => new TranscribeProvider(
            $config,
            $app->make(Dispatcher::class),
        ));
    }
}
