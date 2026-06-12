<?php

declare(strict_types=1);

namespace Clinically\AiTranscribe\Tests;

use Clinically\AiTranscribe\AiTranscribeServiceProvider;
use Laravel\Ai\AiServiceProvider;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            AiTranscribeServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai.providers.aws', [
            'driver' => 'aws-transcribe',
            'region' => 'ap-southeast-2',
            'bucket' => 'test-bucket',
            'prefix' => 'transcriptions',
            'language' => 'en-AU',
            'access_key_id' => 'test-key',
            'secret_access_key' => 'test-secret',
        ]);

        $app['config']->set('ai.default_for_transcription', 'aws');
    }
}
