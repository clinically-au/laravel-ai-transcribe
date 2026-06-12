<?php

declare(strict_types=1);

use Clinically\AiTranscribe\TranscriptParser;
use Laravel\Ai\Responses\Data\TranscriptionSegment;

it('parses a plain transcript', function () {
    $json = file_get_contents(__DIR__.'/../fixtures/transcript-plain.json');

    $parsed = (new TranscriptParser)->parse($json);

    expect($parsed->text)->toBe('Hello world.')
        ->and($parsed->segments)->toBeEmpty();
});

it('parses a diarized transcript into speaker segments', function () {
    $json = file_get_contents(__DIR__.'/../fixtures/transcript-diarized.json');

    $parsed = (new TranscriptParser)->parse($json);

    expect($parsed->text)->toBe('Hello there. Hi, how are you?')
        ->and($parsed->segments)->toHaveCount(2);

    $first = $parsed->segments->first();

    expect($first)->toBeInstanceOf(TranscriptionSegment::class)
        ->and($first->text)->toBe('Hello there.')
        ->and($first->speaker)->toBe('spk_0')
        ->and($first->startSeconds)->toBe(0.0)
        ->and($first->endSeconds)->toBe(1.5);

    $second = $parsed->segments->last();

    expect($second->text)->toBe('Hi, how are you?')
        ->and($second->speaker)->toBe('spk_1')
        ->and($second->startSeconds)->toBe(1.8)
        ->and($second->endSeconds)->toBe(3.4);
});

it('returns an empty transcript when results are absent', function () {
    $parsed = (new TranscriptParser)->parse('{"jobName":"x","status":"COMPLETED"}');

    expect($parsed->text)->toBe('')
        ->and($parsed->segments)->toBeEmpty();
});

it('skips malformed segment entries', function () {
    $parsed = (new TranscriptParser)->parse(json_encode([
        'results' => [
            'transcripts' => [['transcript' => 'Hello.']],
            'audio_segments' => [
                'corrupt-entry',
                ['transcript' => 'Hello.', 'speaker_label' => 'spk_0', 'start_time' => '0.0', 'end_time' => '1.0'],
            ],
        ],
    ]));

    expect($parsed->segments)->toHaveCount(1)
        ->and($parsed->segments->first()->text)->toBe('Hello.');
});

it('rejects invalid json', function () {
    (new TranscriptParser)->parse('not json');
})->throws(InvalidArgumentException::class);
