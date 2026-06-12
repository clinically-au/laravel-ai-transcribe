<?php

declare(strict_types=1);

use Aws\Command;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Clinically\AiTranscribe\Exceptions\TranscriptionJobFailedException;
use Clinically\AiTranscribe\Exceptions\TranscriptionTimedOutException;
use Clinically\AiTranscribe\TranscribeGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Responses\TranscriptionResponse;

it('transcribes audio via a batch job and cleans up both s3 objects', function () {
    Sleep::fake();

    $transcriptJson = file_get_contents(__DIR__.'/../fixtures/transcript-diarized.json');

    $s3Commands = [];
    $s3 = mockedS3(mockedHandler([
        new Result([]), // PutObject
        new Result(['Body' => $transcriptJson]), // GetObject
        new Result([]), // DeleteObject (audio)
        new Result([]), // DeleteObject (transcript)
    ], $s3Commands));

    $transcribeCommands = [];
    $transcribe = mockedTranscribe(mockedHandler([
        new Result([]), // StartTranscriptionJob
        new Result(['TranscriptionJob' => ['TranscriptionJobStatus' => 'IN_PROGRESS']]), // GetTranscriptionJob
        new Result(['TranscriptionJob' => ['TranscriptionJobStatus' => 'COMPLETED']]), // GetTranscriptionJob
    ], $transcribeCommands));

    $gateway = new TranscribeGateway($transcribe, $s3);

    $response = $gateway->generateTranscription(
        makeProvider(),
        'standard',
        new Base64Audio(base64_encode('fake-audio-bytes'), 'audio/mpeg'),
        null,
        true,
    );

    expect($response)->toBeInstanceOf(TranscriptionResponse::class)
        ->and($response->text)->toBe('Hello there. Hi, how are you?')
        ->and($response->segments)->toHaveCount(2)
        ->and($response->meta->provider)->toBe('aws')
        ->and($response->meta->model)->toBe('standard');

    // S3 lifecycle: upload, fetch transcript, delete both objects.
    expect(array_column($s3Commands, 'name'))->toBe(['PutObject', 'GetObject', 'DeleteObject', 'DeleteObject']);

    $put = $s3Commands[0]['params'];

    expect($put['Bucket'])->toBe('test-bucket')
        ->and($put['Key'])->toStartWith('transcriptions/')
        ->and($put['Key'])->toEndWith('.mp3')
        ->and($put['ContentType'])->toBe('audio/mpeg')
        ->and((string) $put['Body'])->toBe('fake-audio-bytes');

    // Transcribe job request.
    expect(array_column($transcribeCommands, 'name'))
        ->toBe(['StartTranscriptionJob', 'GetTranscriptionJob', 'GetTranscriptionJob']);

    $start = $transcribeCommands[0]['params'];

    expect($start['LanguageCode'])->toBe('en-AU')
        ->and($start['Media']['MediaFileUri'])->toBe("s3://test-bucket/{$put['Key']}")
        ->and($start['OutputBucketName'])->toBe('test-bucket')
        ->and($start['OutputKey'])->toStartWith('transcriptions/')
        ->and($start['OutputKey'])->toEndWith('.json')
        ->and($start['Settings']['ShowSpeakerLabels'])->toBeTrue()
        ->and($start['Settings']['MaxSpeakerLabels'])->toBe(10);

    // Both deletes target the objects we created.
    expect($s3Commands[2]['params']['Key'])->toBe($put['Key'])
        ->and($s3Commands[3]['params']['Key'])->toBe($start['OutputKey']);
});

it('passes an explicit language through to the job', function () {
    Sleep::fake();

    $s3Commands = [];
    $s3 = mockedS3(mockedHandler([
        new Result([]),
        new Result(['Body' => file_get_contents(__DIR__.'/../fixtures/transcript-plain.json')]),
        new Result([]),
        new Result([]),
    ], $s3Commands));

    $transcribeCommands = [];
    $transcribe = mockedTranscribe(mockedHandler([
        new Result([]), // StartTranscriptionJob
        new Result(['TranscriptionJob' => ['TranscriptionJobStatus' => 'COMPLETED']]), // GetTranscriptionJob
    ], $transcribeCommands));

    (new TranscribeGateway($transcribe, $s3))->generateTranscription(
        makeProvider(),
        'standard',
        new Base64Audio(base64_encode('bytes'), 'audio/wav'),
        'en-GB',
    );

    expect($transcribeCommands[0]['params']['LanguageCode'])->toBe('en-GB')
        ->and($transcribeCommands[0]['params'])->not->toHaveKey('Settings');
});

it('targets a custom language model when the model is not standard', function () {
    Sleep::fake();

    $s3Commands = [];
    $s3 = mockedS3(mockedHandler([
        new Result([]),
        new Result(['Body' => file_get_contents(__DIR__.'/../fixtures/transcript-plain.json')]),
        new Result([]),
        new Result([]),
    ], $s3Commands));

    $transcribeCommands = [];
    $transcribe = mockedTranscribe(mockedHandler([
        new Result([]), // StartTranscriptionJob
        new Result(['TranscriptionJob' => ['TranscriptionJobStatus' => 'COMPLETED']]), // GetTranscriptionJob
    ], $transcribeCommands));

    (new TranscribeGateway($transcribe, $s3))->generateTranscription(
        makeProvider(),
        'my-clinical-clm',
        new Base64Audio(base64_encode('bytes'), 'audio/mpeg'),
    );

    expect($transcribeCommands[0]['params']['ModelSettings']['LanguageModelName'])->toBe('my-clinical-clm');
});

it('deep merges provider options into the job request last', function () {
    Sleep::fake();

    $s3Commands = [];
    $s3 = mockedS3(mockedHandler([
        new Result([]),
        new Result(['Body' => file_get_contents(__DIR__.'/../fixtures/transcript-plain.json')]),
        new Result([]),
        new Result([]),
    ], $s3Commands));

    $transcribeCommands = [];
    $transcribe = mockedTranscribe(mockedHandler([
        new Result([]), // StartTranscriptionJob
        new Result(['TranscriptionJob' => ['TranscriptionJobStatus' => 'COMPLETED']]), // GetTranscriptionJob
    ], $transcribeCommands));

    (new TranscribeGateway($transcribe, $s3))->generateTranscription(
        makeProvider(),
        'standard',
        new Base64Audio(base64_encode('bytes'), 'audio/mpeg'),
        null,
        true,
        30,
        [
            'Settings' => ['MaxSpeakerLabels' => 4],
            'ContentRedaction' => ['RedactionType' => 'PII', 'RedactionOutput' => 'redacted'],
        ],
    );

    $start = $transcribeCommands[0]['params'];

    expect($start['Settings']['MaxSpeakerLabels'])->toBe(4)
        ->and($start['Settings']['ShowSpeakerLabels'])->toBeTrue()
        ->and($start['ContentRedaction']['RedactionType'])->toBe('PII');
});

it('requires a configured bucket', function () {
    $transcribeCommands = [];
    $s3Commands = [];

    $gateway = new TranscribeGateway(
        mockedTranscribe(mockedHandler([], $transcribeCommands)),
        mockedS3(mockedHandler([], $s3Commands)),
    );

    $gateway->generateTranscription(
        makeProvider(['bucket' => null]),
        'standard',
        new Base64Audio(base64_encode('bytes'), 'audio/mpeg'),
    );
})->throws(InvalidArgumentException::class, 'bucket');

it('throws and still cleans up when the job fails', function () {
    Sleep::fake();

    $s3Commands = [];
    $s3 = mockedS3(mockedHandler([
        new Result([]), // PutObject
        new Result([]), // DeleteObject (audio)
        new Result([]), // DeleteObject (transcript — may not exist; delete is idempotent)
    ], $s3Commands));

    $transcribeCommands = [];
    $transcribe = mockedTranscribe(mockedHandler([
        new Result([]), // StartTranscriptionJob
        new Result(['TranscriptionJob' => [
            'TranscriptionJobStatus' => 'FAILED',
            'FailureReason' => 'The media format is not supported.',
        ]]), // GetTranscriptionJob
    ], $transcribeCommands));

    $gateway = new TranscribeGateway($transcribe, $s3);

    try {
        $gateway->generateTranscription(
            makeProvider(),
            'standard',
            new Base64Audio(base64_encode('bytes'), 'audio/mpeg'),
        );

        $this->fail('Expected TranscriptionJobFailedException.');
    } catch (TranscriptionJobFailedException $e) {
        expect($e->failureReason)->toBe('The media format is not supported.');
    }

    expect(array_column($s3Commands, 'name'))->toBe(['PutObject', 'DeleteObject', 'DeleteObject']);
});

it('abandons the job and cleans up when polling times out', function () {
    Sleep::fake();

    $s3Commands = [];
    $s3 = mockedS3(mockedHandler([
        new Result([]), // PutObject
        new Result([]), // DeleteObject (audio)
        new Result([]), // DeleteObject (transcript)
    ], $s3Commands));

    $transcribeCommands = [];
    $transcribe = mockedTranscribe(mockedHandler([
        new Result([]), // StartTranscriptionJob
        new Result(['TranscriptionJob' => ['TranscriptionJobStatus' => 'IN_PROGRESS']]), // GetTranscriptionJob
        new Result([]), // DeleteTranscriptionJob
    ], $transcribeCommands));

    $gateway = new TranscribeGateway($transcribe, $s3);

    expect(fn () => $gateway->generateTranscription(
        makeProvider(),
        'standard',
        new Base64Audio(base64_encode('bytes'), 'audio/mpeg'),
        null,
        false,
        0, // timeout immediately after the first poll
    ))->toThrow(TranscriptionTimedOutException::class);

    expect(array_column($transcribeCommands, 'name'))
        ->toBe(['StartTranscriptionJob', 'GetTranscriptionJob', 'DeleteTranscriptionJob'])
        ->and(array_column($s3Commands, 'name'))->toBe(['PutObject', 'DeleteObject', 'DeleteObject']);
});

it('does not mask the result when cleanup fails', function () {
    Sleep::fake();
    Log::shouldReceive('warning')->twice();

    $transcriptJson = file_get_contents(__DIR__.'/../fixtures/transcript-plain.json');

    $s3Commands = [];
    $s3 = mockedS3(mockedHandler([
        new Result([]), // PutObject
        new Result(['Body' => $transcriptJson]), // GetObject
        new S3Exception('Access denied.', new Command('DeleteObject')),
        new S3Exception('Access denied.', new Command('DeleteObject')),
    ], $s3Commands));

    $transcribeCommands = [];
    $transcribe = mockedTranscribe(mockedHandler([
        new Result([]), // StartTranscriptionJob
        new Result(['TranscriptionJob' => ['TranscriptionJobStatus' => 'COMPLETED']]), // GetTranscriptionJob
    ], $transcribeCommands));

    $response = (new TranscribeGateway($transcribe, $s3))->generateTranscription(
        makeProvider(),
        'standard',
        new Base64Audio(base64_encode('bytes'), 'audio/mpeg'),
    );

    expect($response->text)->toBe('Hello world.');
});
