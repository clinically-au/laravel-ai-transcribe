<?php

declare(strict_types=1);

use Clinically\AiTranscribe\Exceptions\TranscriptionJobFailedException;
use Clinically\AiTranscribe\Exceptions\TranscriptionTimedOutException;

it('describes a failed transcription job', function () {
    $exception = new TranscriptionJobFailedException('job-abc', 'Unsupported media format.');

    expect($exception->jobName)->toBe('job-abc')
        ->and($exception->failureReason)->toBe('Unsupported media format.')
        ->and($exception->getMessage())->toBe('Amazon Transcribe job [job-abc] failed: Unsupported media format.');
});

it('describes a timed out transcription job', function () {
    $exception = new TranscriptionTimedOutException('job-abc', 30);

    expect($exception->jobName)->toBe('job-abc')
        ->and($exception->timeout)->toBe(30)
        ->and($exception->getMessage())->toBe('Amazon Transcribe job [job-abc] did not complete within 30 seconds.');
});
