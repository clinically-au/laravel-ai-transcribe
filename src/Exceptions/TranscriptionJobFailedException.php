<?php

declare(strict_types=1);

namespace Clinically\AiTranscribe\Exceptions;

use RuntimeException;

final class TranscriptionJobFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $jobName,
        public readonly string $failureReason,
    ) {
        parent::__construct("Amazon Transcribe job [{$jobName}] failed: {$failureReason}");
    }
}
