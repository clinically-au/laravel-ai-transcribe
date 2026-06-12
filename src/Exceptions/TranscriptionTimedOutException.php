<?php

declare(strict_types=1);

namespace Clinically\AiTranscribe\Exceptions;

use RuntimeException;

final class TranscriptionTimedOutException extends RuntimeException
{
    public function __construct(
        public readonly string $jobName,
        int $timeout,
    ) {
        parent::__construct("Amazon Transcribe job [{$jobName}] did not complete within {$timeout} seconds.");
    }
}
