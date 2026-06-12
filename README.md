# clinically/laravel-ai-transcribe

Amazon Transcribe driver for the official [Laravel AI SDK](https://github.com/laravel/ai).
Transcribe audio in your own AWS region (e.g. `ap-southeast-2`) with Amazon
Transcribe batch jobs — including diarization, custom language models, and
PII redaction — through the SDK's standard `Transcription` API.

## Why

The Laravel AI SDK has no AWS speech-to-text provider. For clinical audio,
data residency matters: this driver keeps audio, inference, and transcripts in
the region you configure. The S3 objects it creates are **ephemeral** — the
uploaded audio and the transcript JSON are deleted as soon as the job finishes,
succeed or fail.

## Installation

```bash
composer require clinically/laravel-ai-transcribe
```

Add a provider connection to `config/ai.php`:

```php
'providers' => [
    'aws' => [
        'driver' => 'aws-transcribe',
        'region' => env('AWS_TRANSCRIBE_REGION', 'ap-southeast-2'),
        'bucket' => env('AWS_TRANSCRIBE_BUCKET'),
        'prefix' => 'transcriptions',
        'language' => 'en-AU',
        // Omit the keys below to use the AWS default credential chain
        // (instance profile, IRSA, environment variables, etc).
        'access_key_id' => env('AWS_ACCESS_KEY_ID'),
        'secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
        'session_token' => env('AWS_SESSION_TOKEN'),
    ],
],

'default_for_transcription' => 'aws',
```

## Usage

```php
use Laravel\Ai\Transcription;

$response = Transcription::fromStorage('consults/recording.mp3')->generate();

echo $response->text;
```

Diarization (speaker labels per segment):

```php
$response = Transcription::fromPath($path)->diarize()->generate();

foreach ($response->segments as $segment) {
    echo "{$segment->speaker}: {$segment->text}\n";
}
```

Custom language models and any other `StartTranscriptionJob` options pass
through `providerOptions()` (or set a default model in config):

```php
'models' => ['transcription' => ['default' => 'my-clinical-clm']],
```

```php
Transcription::fromPath($path)
    ->providerOptions([
        'ContentRedaction' => ['RedactionType' => 'PII', 'RedactionOutput' => 'redacted'],
    ])
    ->generate();
```

Queued transcription, fakes, and events work exactly as documented by the
Laravel AI SDK — this package only adds the driver.

## AWS requirements

- An S3 bucket in the same region (the driver uploads audio to it and deletes
  both audio and transcript when done).
- IAM permissions: `transcribe:StartTranscriptionJob`,
  `transcribe:GetTranscriptionJob`, `transcribe:DeleteTranscriptionJob`,
  `s3:PutObject`, `s3:GetObject`, `s3:DeleteObject` on the configured bucket/prefix.

## Behaviour notes

- The gateway polls the batch job with capped backoff (2s → 10s) up to the
  SDK timeout (default 30s; pass a longer timeout for long recordings, or use
  the SDK's queued transcriptions).
- On timeout the driver also deletes the remote job, so abandoned jobs don't
  keep processing audio.
- `Usage` is empty — Amazon Transcribe bills per second of audio, not tokens.

## Testing your app

Use the SDK's built-in fake — no AWS calls are made:

```php
use Laravel\Ai\Transcription;

Transcription::fake();
```

## License

MIT
