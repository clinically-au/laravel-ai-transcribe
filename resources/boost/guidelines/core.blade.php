## clinically/laravel-ai-transcribe

This package provides an `aws-transcribe` driver for the official Laravel AI SDK, backed by Amazon Transcribe batch jobs. Use it through the SDK's standard transcription API — never call AWS clients directly for transcription.

### Features

- Regional transcription (e.g. `ap-southeast-2`) with ephemeral S3 lifecycle: uploaded audio and transcript JSON are always deleted after the job.
- Diarization maps to `TranscriptionSegment` objects (speaker, start/end seconds, text).
- Custom language models: set `models.transcription.default` on the provider connection, or pass any `StartTranscriptionJob` option (e.g. `ContentRedaction`) via provider options. Example:

@verbatim
<code-snippet name="Transcribing with the aws provider" lang="php">
use Laravel\Ai\Transcription;

$response = Transcription::fromStorage('consults/recording.mp3')->generate();

$text = $response->text;
$segments = $response->segments; // speaker-labelled when diarized
</code-snippet>
@endverbatim

### Conventions

- The provider connection lives in `config/ai.php` under `providers` with `'driver' => 'aws-transcribe'`, plus `region`, `bucket`, `prefix`, and `language` keys.
- Prefer the AWS default credential chain in production (omit explicit keys).
- For recordings longer than ~30 seconds of processing, pass a longer timeout or use the SDK's queued transcriptions.
- Test with `Transcription::fake()` — never hit AWS in tests.
