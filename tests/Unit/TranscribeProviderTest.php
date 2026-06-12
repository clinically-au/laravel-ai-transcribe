<?php

declare(strict_types=1);

use Clinically\AiTranscribe\TranscribeGateway;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;

it('is a laravel/ai transcription provider', function () {
    expect(makeProvider())->toBeInstanceOf(TranscriptionProvider::class);
});

it('exposes filtered aws credentials', function () {
    expect(makeProvider()->providerCredentials())->toBe([
        'access_key_id' => 'test-key',
        'secret_access_key' => 'test-secret',
    ]);
});

it('omits absent credentials so the default chain is used', function () {
    $provider = makeProvider([
        'access_key_id' => null,
        'secret_access_key' => null,
    ]);

    expect($provider->providerCredentials())->toBe([]);
});

it('exposes regional configuration with australian defaults', function () {
    $provider = makeProvider([
        'region' => null,
        'language' => null,
        'prefix' => null,
    ]);

    $config = $provider->additionalConfiguration();

    expect($config['region'])->toBe('ap-southeast-2')
        ->and($config['language'])->toBe('en-AU')
        ->and($config['prefix'])->toBe('transcriptions')
        ->and($config['bucket'])->toBe('test-bucket');
});

it('trims slashes from the configured prefix', function () {
    $provider = makeProvider(['prefix' => '/audio/jobs/']);

    expect($provider->additionalConfiguration()['prefix'])->toBe('audio/jobs');
});

it('defaults to the standard transcription model', function () {
    expect(makeProvider()->defaultTranscriptionModel())->toBe('standard');
});

it('allows a custom language model as the default model', function () {
    $provider = makeProvider([
        'models' => ['transcription' => ['default' => 'my-clinical-clm']],
    ]);

    expect($provider->defaultTranscriptionModel())->toBe('my-clinical-clm');
});

it('lazily builds a transcribe gateway', function () {
    expect(makeProvider()->transcriptionGateway())->toBeInstanceOf(TranscribeGateway::class);
});
