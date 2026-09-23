<?php

declare(strict_types=1);

use Aws\Result;
use Clinically\AiTranscribe\TranscribeGateway;
use Clinically\AiTranscribe\TranscribeProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\TranscriptionGenerated;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Responses\Data\TranscriptionUsage;
use Laravel\Ai\Transcription;

it('resolves the aws-transcribe driver from the ai manager', function () {
    $provider = Ai::transcriptionProvider('aws');

    expect($provider)->toBeInstanceOf(TranscribeProvider::class)
        ->and($provider->name())->toBe('aws')
        ->and($provider->defaultTranscriptionModel())->toBe('standard');
});

it('is the default transcription provider', function () {
    expect(Ai::transcriptionProvider())->toBeInstanceOf(TranscribeProvider::class);
});

it('transcribes end to end through the sdk provider', function () {
    Sleep::fake();

    Ai::forgetInstance('aws');

    Event::fake([TranscriptionGenerated::class]);

    $transcriptJson = file_get_contents(__DIR__.'/../fixtures/transcript-diarized.json');

    $s3Commands = [];
    $s3 = mockedS3(mockedHandler([
        new Result([]),
        new Result(['Body' => $transcriptJson]),
        new Result([]),
        new Result([]),
    ], $s3Commands));

    $transcribeCommands = [];
    $transcribe = mockedTranscribe(mockedHandler([
        new Result([]), // StartTranscriptionJob
        new Result(['TranscriptionJob' => ['TranscriptionJobStatus' => 'COMPLETED']]), // GetTranscriptionJob
    ], $transcribeCommands));

    $provider = Ai::transcriptionProvider('aws');
    $provider->useTranscriptionGateway(new TranscribeGateway($transcribe, $s3));

    $response = $provider->transcribe(
        new Base64Audio(base64_encode('fake-audio'), 'audio/mpeg'),
        diarize: true,
    );

    expect((string) $response)->toBe('Hello there. Hi, how are you?')
        ->and($response->segments)->toHaveCount(2)
        ->and($response->usage)->toBeInstanceOf(TranscriptionUsage::class)
        ->and($response->usage->audioSeconds)->toBeNull();

    Event::assertDispatched(TranscriptionGenerated::class);
});

it('supports sdk transcription fakes without calling aws', function () {
    Transcription::fake(['Synthetic transcript.'])->preventStrayTranscriptions();

    $response = Transcription::fromBase64(base64_encode('synthetic audio'), 'audio/mpeg')
        ->diarize()
        ->generate(provider: 'aws');

    expect($response->text)->toBe('Synthetic transcript.');

    Transcription::assertGenerated(fn (TranscriptionPrompt $prompt): bool => $prompt->isDiarized()
        && $prompt->provider->name() === 'aws');
});
