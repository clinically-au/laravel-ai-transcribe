<?php

declare(strict_types=1);

namespace Clinically\AiTranscribe\Data;

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\TranscriptionSegment;

final readonly class ParsedTranscript
{
    /**
     * @param  Collection<int, TranscriptionSegment>  $segments
     */
    public function __construct(
        public string $text,
        public Collection $segments,
    ) {}
}
