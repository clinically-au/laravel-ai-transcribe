# Design: clinically/laravel-ai-transcribe

**Date:** 2026-06-12
**Status:** Approved

## Purpose

A custom transcription driver for the official `laravel/ai` SDK backed by Amazon
Transcribe (batch API). Motivation: regional inference / data residency — clinical
audio must be transcribed in `ap-southeast-2` — and access to Amazon Transcribe
features (custom language models, vocabularies, diarization, PII redaction) that no
built-in `laravel/ai` provider offers. The built-in `BedrockProvider` does not
implement `TranscriptionProvider`, so this package fills that gap.

## Integration surface

`laravel/ai`'s `AiManager` extends `Illuminate\Support\MultipleInstanceManager`, so
the package registers the driver without touching the SDK:

```php
Ai::extend('aws-transcribe', fn ($app, array $config) => new TranscribeProvider($config, $app['events']));
```

Registration is guarded with `class_exists(\Laravel\Ai\Ai::class)`.

The package implements exactly two SDK interfaces:

- `Laravel\Ai\Contracts\Providers\TranscriptionProvider` — the provider/connection.
- `Laravel\Ai\Contracts\Gateway\TranscriptionGateway` — the API call, returning a
  `Laravel\Ai\Responses\TranscriptionResponse` (`text`, `segments`, `usage`, `meta`).

Sync, queued (`GenerateTranscription` job re-enters the same sync path), fakes, and
events all work for free through the SDK.

## Package shape

- **Name:** `clinically/laravel-ai-transcribe`
- **Namespace:** `Clinically\AiTranscribe`
- **Driver name:** `aws-transcribe`
- **No package config file, no migrations, no UI.** The connection lives in the
  consuming app's `config/ai.php` providers array, per `laravel/ai` convention.

```php
'providers' => [
    'aws' => [
        'driver' => 'aws-transcribe',
        'region' => env('AWS_TRANSCRIBE_REGION', 'ap-southeast-2'),
        'bucket' => env('AWS_TRANSCRIBE_BUCKET'),
        'prefix' => 'transcriptions',
        'use_default_credential_provider' => true,
        // optional: access_key_id, secret_access_key, session_token
        'models' => ['transcription' => ['default' => 'standard']],
        'language' => 'en-AU',
    ],
],
'default_for_transcription' => 'aws',
```

Usage: `Transcription::fromStorage('consult.mp3')->generate()` (provider
selected via `default_for_transcription`; the SDK has no `using()` method —
corrected post-implementation). The `use_default_credential_provider` key
above was dropped during implementation: credential selection is implied by
the presence/absence of explicit keys.

## Components

| Unit | Responsibility |
| --- | --- |
| `TranscribeServiceProvider` | Registers the `aws-transcribe` driver via `Ai::extend()`. |
| `TranscribeProvider` | Extends `Laravel\Ai\Providers\Provider`, implements `TranscriptionProvider`, reuses the SDK's `GeneratesTranscriptions` / `HasTranscriptionGateway` concerns (same pattern as `ElevenLabsProvider`). Credentials mirror `BedrockProvider`: explicit keys or the AWS default credential chain. |
| `TranscribeGateway` | Implements `TranscriptionGateway`. Owns `TranscribeServiceClient` and `S3Client` (constructor-injected for testability). Orchestrates upload → job → poll → parse → cleanup. |
| `TranscriptParser` | Pure class: Transcribe result JSON → full text + segment collection (speaker label, start/end seconds, text) when diarized. |
| `TranscriptionJobFailedException` | Carries AWS `FailureReason`. |
| `TranscriptionTimedOutException` | Thrown when polling exceeds `$timeout`. |

## Data flow (`generateTranscription`)

1. Read bytes from the `TranscribableAudio`; upload to
   `s3://{bucket}/{prefix}/{uuid}.{ext}`.
2. `StartTranscriptionJob`:
   - `LanguageCode` from the `$language` parameter, falling back to config
     `language` (default `en-AU`);
   - `Settings.ShowSpeakerLabels` + `MaxSpeakerLabels` when `$diarize`;
   - output written to the same bucket/prefix;
   - `$providerOptions` deep-merged into the request **last** — escape hatch for
     custom language models (`ModelSettings.LanguageModelName`), vocabularies,
     and content/PII redaction.
3. Poll `GetTranscriptionJob` every 2s with capped backoff until
   `COMPLETED` / `FAILED` or `$timeout` exceeded.
4. On success: fetch transcript JSON from S3, parse via `TranscriptParser`,
   build `TranscriptionResponse`. `Usage` is empty (the SDK's `Usage` is
   token-based; Transcribe bills per second — duration is derivable from the
   last segment's end time); `Meta` carries provider name + model.
5. **`finally`:** delete the uploaded audio object and the transcript JSON
   object — ephemeral S3 lifecycle, nothing persists (PHI minimisation).
   Deletion is best-effort; failures log a warning, never mask the result.
6. On timeout: also fire a best-effort `DeleteTranscriptionJob` so the orphaned
   job stops processing PHI, then throw `TranscriptionTimedOutException`.
7. On `FAILED`: throw `TranscriptionJobFailedException` with the AWS reason.

## Error handling summary

- AWS SDK exceptions propagate (credentials, permissions, throttling) — the
  consuming app's retry/queue machinery handles them.
- Job failure and timeout get dedicated package exceptions.
- S3 cleanup always runs (`finally`), is best-effort, and logs on failure.

## Testing

- Pest + Orchestra Testbench per Clinically package conventions.
- **Unit:** `TranscriptParser` against fixture JSON (diarized and plain).
- **Feature:** full gateway flow with AWS SDK `MockHandler` queues — happy path,
  job `FAILED`, polling timeout, and cleanup-always-runs (including on failure).
- PHPStan level 6, Pint with `declare_strict_types`.

## Out of scope (deliberate)

- Transcribe Medical (US regions only — defeats the residency goal).
- Transcribe streaming API (live audio; awkward event-stream support in PHP SDK).
- Skip-upload optimisation for audio already in S3.
- Boost guidelines file is included but minimal.
