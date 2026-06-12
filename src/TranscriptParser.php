<?php

declare(strict_types=1);

namespace Clinically\AiTranscribe;

use Clinically\AiTranscribe\Data\ParsedTranscript;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\Responses\Data\TranscriptionSegment;

final class TranscriptParser
{
    public function parse(string $json): ParsedTranscript
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw new InvalidArgumentException('Invalid transcript JSON received from Amazon Transcribe.');
        }

        $results = $data['results'] ?? [];

        $text = $results['transcripts'][0]['transcript'] ?? '';

        $segments = (new Collection($results['audio_segments'] ?? []))
            ->filter(fn (mixed $segment): bool => is_array($segment))
            ->map(fn (array $segment): TranscriptionSegment => new TranscriptionSegment(
                $segment['transcript'] ?? '',
                $segment['speaker_label'] ?? '',
                (float) ($segment['start_time'] ?? 0),
                (float) ($segment['end_time'] ?? 0),
            ))
            ->values();

        return new ParsedTranscript($text, $segments);
    }
}
