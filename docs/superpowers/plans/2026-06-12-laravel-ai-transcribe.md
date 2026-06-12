# clinically/laravel-ai-transcribe Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An `aws-transcribe` driver for the official `laravel/ai` SDK backed by Amazon Transcribe (batch API) with ephemeral S3 lifecycle, for regional (ap-southeast-2) clinical transcription.

**Architecture:** A `TranscribeProvider` (implements `Laravel\Ai\Contracts\Providers\TranscriptionProvider`) registered via `Ai::extend('aws-transcribe', ...)`, delegating to a `TranscribeGateway` (implements `Laravel\Ai\Contracts\Gateway\TranscriptionGateway`) that uploads audio to S3, runs a batch transcription job, polls with capped backoff, parses the result JSON via a pure `TranscriptParser`, and always deletes both S3 objects in a `finally` block.

**Tech Stack:** PHP 8.4, Laravel 12/13, `laravel/ai` ^0.7, `aws/aws-sdk-php` ^3.339 (TranscribeService + S3 clients), Pest 3 + Orchestra Testbench, PHPStan level 6, Pint.

**Working directory for ALL commands:** `/Users/wojt/Code/clinically-au/laravel-ai-transcribe`

**Spec:** `docs/superpowers/specs/2026-06-12-laravel-ai-transcribe-design.md`

---

## Verified facts about laravel/ai v0.7 (do not re-derive)

These were read directly from `infra/vendor/laravel/ai`:

- `Ai` (`Laravel\Ai\Ai`) is a Facade for `AiManager`, which extends `Illuminate\Support\MultipleInstanceManager`. `Ai::extend('aws-transcribe', $closure)` registers a custom creator; the closure is invoked as `$closure($app, array $config)` where `$config` is the entry from `config('ai.providers.{name}')` with `'name' => {name}` injected.
- `Laravel\Ai\Providers\Provider` (abstract base) constructor is `(Gateway $gateway, array $config, Dispatcher $events)`, but the built-in `BedrockProvider` overrides it as `(protected array $config, protected Dispatcher $events)` and never initialises `$gateway` — we mirror that exactly.
- The SDK ships traits `Laravel\Ai\Providers\Concerns\GeneratesTranscriptions` (implements `transcribe()`, dispatches `GeneratingTranscription`/`TranscriptionGenerated` events, handles fakes) and `Laravel\Ai\Providers\Concerns\HasTranscriptionGateway` (gateway get/set). We must override `transcriptionGateway()` in the provider class because the trait's fallback reads the uninitialised `$gateway` property.
- `TranscriptionGateway::generateTranscription(TranscriptionProvider $provider, string $model, TranscribableAudio $audio, ?string $language, bool $diarize, int $timeout = 30, array $providerOptions = []): TranscriptionResponse`.
- `TranscribableAudio` extends `HasContent` (`content(): string` — raw bytes) and `HasMimeType` (`mimeType(): ?string`).
- `TranscriptionResponse::__construct(string $text, Collection $segments, Usage $usage, Meta $meta)`.
- `TranscriptionSegment::__construct(string $text, string $speaker, float $startSeconds, float $endSeconds)`.
- `Usage` is token-based only (all-zero default is correct for Transcribe). `Meta::__construct(?string $provider = null, ?string $model = null, ?Collection $citations = null)`.
- `Provider::name()` returns `$this->config['name']`.
- The SDK's own service provider for Testbench is `Laravel\Ai\AiServiceProvider`.
- `Laravel\Ai\Files\Base64Audio` exists: `new Base64Audio(base64_encode($bytes), 'audio/mpeg')` — use it in tests, no binary fixtures needed.

## File structure

```
laravel-ai-transcribe/
├── src/
│   ├── AiTranscribeServiceProvider.php       # registers the driver via Ai::extend()
│   ├── TranscribeProvider.php                # TranscriptionProvider implementation
│   ├── TranscribeGateway.php                 # TranscriptionGateway implementation
│   ├── TranscriptParser.php                  # pure JSON → ParsedTranscript
│   ├── Data/
│   │   └── ParsedTranscript.php              # readonly DTO (text + segments)
│   └── Exceptions/
│       ├── TranscriptionJobFailedException.php
│       └── TranscriptionTimedOutException.php
├── tests/
│   ├── TestCase.php
│   ├── Pest.php                              # uses() + AWS mock helpers
│   ├── fixtures/
│   │   ├── transcript-plain.json
│   │   └── transcript-diarized.json
│   ├── Unit/
│   │   ├── TranscriptParserTest.php
│   │   └── TranscribeProviderTest.php
│   └── Feature/
│       ├── TranscribeGatewayTest.php
│       └── DriverRegistrationTest.php
├── docs/superpowers/...                      # spec + this plan (already committed)
├── composer.json / pint.json / phpstan.neon / phpunit.xml / .gitignore
├── CLAUDE.md / README.md
└── resources/boost/guidelines/core.blade.php
```

No config dir, no migrations, no views: the connection config lives in the consuming app's `config/ai.php` (spec §Package shape).

---

### Task 1: Package skeleton

**Files:**
- Create: `composer.json`
- Create: `pint.json`
- Create: `phpstan.neon`
- Create: `phpunit.xml`
- Create: `.gitignore`
- Create: `tests/TestCase.php`
- Create: `tests/Pest.php`

- [ ] **Step 1: Write `composer.json`**

```json
{
    "name": "clinically/laravel-ai-transcribe",
    "description": "Amazon Transcribe driver for the official Laravel AI SDK.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.4",
        "aws/aws-sdk-php": "^3.339",
        "illuminate/support": "^12.0|^13.0",
        "laravel/ai": "^0.7"
    },
    "require-dev": {
        "orchestra/testbench": "^10.0|^11.0",
        "pestphp/pest": "^3.0",
        "pestphp/pest-plugin-laravel": "^3.0",
        "larastan/larastan": "^3.0",
        "laravel/pint": "^1.0"
    },
    "autoload": {
        "psr-4": { "Clinically\\AiTranscribe\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "Clinically\\AiTranscribe\\Tests\\": "tests/" }
    },
    "extra": {
        "laravel": {
            "providers": ["Clinically\\AiTranscribe\\AiTranscribeServiceProvider"]
        }
    },
    "scripts": {
        "test": "pest",
        "analyse": "phpstan analyse",
        "format": "pint"
    },
    "config": {
        "allow-plugins": {
            "pestphp/pest-plugin": true
        }
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

- [ ] **Step 2: Write `pint.json`**

```json
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": true
    }
}
```

- [ ] **Step 3: Write `phpstan.neon`**

```neon
includes:
  - vendor/larastan/larastan/extension.neon

parameters:
  paths:
    - src
  level: 6
  tmpDir: phpstan.cache
```

(No `ignoreErrors` yet — add only if a real error needs suppressing; unmatched ignores are themselves errors.)

- [ ] **Step 4: Write `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 5: Write `.gitignore`**

```
/vendor
/phpstan.cache
.phpunit.result.cache
composer.lock
.DS_Store
```

- [ ] **Step 6: Write `tests/TestCase.php`**

```php
<?php

declare(strict_types=1);

namespace Clinically\AiTranscribe\Tests;

use Clinically\AiTranscribe\AiTranscribeServiceProvider;
use Laravel\Ai\AiServiceProvider;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            AiTranscribeServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai.providers.aws', [
            'driver' => 'aws-transcribe',
            'region' => 'ap-southeast-2',
            'bucket' => 'test-bucket',
            'prefix' => 'transcriptions',
            'language' => 'en-AU',
            'access_key_id' => 'test-key',
            'secret_access_key' => 'test-secret',
        ]);

        $app['config']->set('ai.default_for_transcription', 'aws');
    }
}
```

- [ ] **Step 7: Write `tests/Pest.php`** (includes the AWS mock helpers every later task uses)

```php
<?php

declare(strict_types=1);

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\S3\S3Client;
use Aws\TranscribeService\TranscribeServiceClient;
use Clinically\AiTranscribe\TranscribeProvider;

uses(Clinically\AiTranscribe\Tests\TestCase::class)->in('Unit', 'Feature');

/**
 * Build a MockHandler that records each command's name and params
 * into $commands and returns the queued results in order.
 *
 * @param  array<int, \Aws\ResultInterface|\Aws\Exception\AwsException>  $results
 * @param  array<int, array{name: string, params: array<string, mixed>}>  $commands
 */
function mockedHandler(array $results, array &$commands): MockHandler
{
    $handler = new MockHandler;

    foreach ($results as $result) {
        $handler->append(function (CommandInterface $command) use (&$commands, $result) {
            $commands[] = ['name' => $command->getName(), 'params' => $command->toArray()];

            return $result;
        });
    }

    return $handler;
}

function mockedS3(MockHandler $handler): S3Client
{
    return new S3Client([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'handler' => $handler,
        'credentials' => ['key' => 'test', 'secret' => 'test'],
    ]);
}

function mockedTranscribe(MockHandler $handler): TranscribeServiceClient
{
    return new TranscribeServiceClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'handler' => $handler,
        'credentials' => ['key' => 'test', 'secret' => 'test'],
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeProvider(array $overrides = []): TranscribeProvider
{
    return new TranscribeProvider(array_merge([
        'driver' => 'aws-transcribe',
        'name' => 'aws',
        'region' => 'ap-southeast-2',
        'bucket' => 'test-bucket',
        'prefix' => 'transcriptions',
        'language' => 'en-AU',
        'access_key_id' => 'test-key',
        'secret_access_key' => 'test-secret',
    ], $overrides), app('events'));
}
```

- [ ] **Step 8: Install dependencies**

Run: `composer install`
Expected: succeeds, `vendor/` created. (Pest plugin allowed via `config.allow-plugins`.)

- [ ] **Step 9: Commit**

```bash
git add composer.json pint.json phpstan.neon phpunit.xml .gitignore tests/
git commit -m "chore: scaffold package skeleton"
```

Note: `tests/Pest.php` references `TranscribeProvider`, which doesn't exist yet — that's fine; PHP only resolves it when a helper is called. The suite has no tests yet, so don't run `pest` in this task.

---

### Task 2: Exceptions

**Files:**
- Create: `src/Exceptions/TranscriptionJobFailedException.php`
- Create: `src/Exceptions/TranscriptionTimedOutException.php`
- Test: `tests/Unit/ExceptionsTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/ExceptionsTest.php`:

```php
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
        ->and($exception->getMessage())->toBe('Amazon Transcribe job [job-abc] did not complete within 30 seconds.');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/ExceptionsTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the exceptions**

`src/Exceptions/TranscriptionJobFailedException.php`:

```php
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
```

`src/Exceptions/TranscriptionTimedOutException.php`:

```php
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/ExceptionsTest.php`
Expected: 2 passed.

- [ ] **Step 5: Commit**

```bash
git add src/Exceptions tests/Unit/ExceptionsTest.php
git commit -m "feat: add transcription job exceptions"
```

---

### Task 3: TranscriptParser + ParsedTranscript DTO

**Files:**
- Create: `src/Data/ParsedTranscript.php`
- Create: `src/TranscriptParser.php`
- Create: `tests/fixtures/transcript-plain.json`
- Create: `tests/fixtures/transcript-diarized.json`
- Test: `tests/Unit/TranscriptParserTest.php`

Amazon Transcribe batch output JSON has this shape (modern output includes
`results.audio_segments`, each one a contiguous chunk of speech with optional
`speaker_label` when diarization was enabled):

- [ ] **Step 1: Write the fixtures**

`tests/fixtures/transcript-plain.json`:

```json
{
    "jobName": "job-plain",
    "accountId": "123456789012",
    "status": "COMPLETED",
    "results": {
        "transcripts": [
            { "transcript": "Hello world." }
        ],
        "items": []
    }
}
```

`tests/fixtures/transcript-diarized.json`:

```json
{
    "jobName": "job-diarized",
    "accountId": "123456789012",
    "status": "COMPLETED",
    "results": {
        "transcripts": [
            { "transcript": "Hello there. Hi, how are you?" }
        ],
        "speaker_labels": {
            "speakers": 2,
            "segments": []
        },
        "audio_segments": [
            {
                "id": 0,
                "transcript": "Hello there.",
                "start_time": "0.0",
                "end_time": "1.5",
                "speaker_label": "spk_0",
                "items": [0, 1]
            },
            {
                "id": 1,
                "transcript": "Hi, how are you?",
                "start_time": "1.8",
                "end_time": "3.4",
                "speaker_label": "spk_1",
                "items": [2, 3, 4, 5]
            }
        ],
        "items": []
    }
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Unit/TranscriptParserTest.php`:

```php
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

    expect($second->speaker)->toBe('spk_1')
        ->and($second->startSeconds)->toBe(1.8)
        ->and($second->endSeconds)->toBe(3.4);
});

it('rejects invalid json', function () {
    (new TranscriptParser)->parse('not json');
})->throws(InvalidArgumentException::class);
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/TranscriptParserTest.php`
Expected: FAIL — `TranscriptParser` not found.

- [ ] **Step 4: Write the DTO**

`src/Data/ParsedTranscript.php`:

```php
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
```

- [ ] **Step 5: Write the parser**

`src/TranscriptParser.php`:

```php
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
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/TranscriptParserTest.php`
Expected: 3 passed.

- [ ] **Step 7: Commit**

```bash
git add src/Data src/TranscriptParser.php tests/Unit/TranscriptParserTest.php tests/fixtures
git commit -m "feat: parse Amazon Transcribe result JSON into text and speaker segments"
```

---

### Task 4: TranscribeProvider

**Files:**
- Create: `src/TranscribeProvider.php`
- Test: `tests/Unit/TranscribeProviderTest.php`

The provider mirrors the SDK's built-in `BedrockProvider`: constructor takes
`(array $config, Dispatcher $events)`, credentials come from explicit keys or
fall back to the AWS default credential chain (instance profile / IRSA / env).

- [ ] **Step 1: Write the failing tests**

`tests/Unit/TranscribeProviderTest.php`:

```php
<?php

declare(strict_types=1);

use Clinically\AiTranscribe\TranscribeGateway;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;

it('is a laravel/ai transcription provider', function () {
    expect(makeProvider())->toBeInstanceOf(TranscriptionProvider::class);
});

it('exposes filtered aws credentials', function () {
    expect(makeProvider()->providerCredentials())->toBe([
        'access_key_id' => 'test-key',
        'secret_access_key' => 'test-secret',
    ]);
});

it('omits absent credentials so the default chain is used', function () {
    $provider = makeProvider([
        'access_key_id' => null,
        'secret_access_key' => null,
    ]);

    expect($provider->providerCredentials())->toBe([]);
});

it('exposes regional configuration with australian defaults', function () {
    $provider = makeProvider([
        'region' => null,
        'language' => null,
        'prefix' => null,
    ]);

    $config = $provider->additionalConfiguration();

    expect($config['region'])->toBe('ap-southeast-2')
        ->and($config['language'])->toBe('en-AU')
        ->and($config['prefix'])->toBe('transcriptions')
        ->and($config['bucket'])->toBe('test-bucket');
});

it('trims slashes from the configured prefix', function () {
    $provider = makeProvider(['prefix' => '/audio/jobs/']);

    expect($provider->additionalConfiguration()['prefix'])->toBe('audio/jobs');
});

it('defaults to the standard transcription model', function () {
    expect(makeProvider()->defaultTranscriptionModel())->toBe('standard');
});

it('allows a custom language model as the default model', function () {
    $provider = makeProvider([
        'models' => ['transcription' => ['default' => 'my-clinical-clm']],
    ]);

    expect($provider->defaultTranscriptionModel())->toBe('my-clinical-clm');
});

it('lazily builds a transcribe gateway', function () {
    expect(makeProvider()->transcriptionGateway())->toBeInstanceOf(TranscribeGateway::class);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/TranscribeProviderTest.php`
Expected: FAIL — `TranscribeProvider` not found.

- [ ] **Step 3: Write the provider**

`src/TranscribeProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Clinically\AiTranscribe;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Providers\Concerns\GeneratesTranscriptions;
use Laravel\Ai\Providers\Concerns\HasTranscriptionGateway;
use Laravel\Ai\Providers\Provider;

final class TranscribeProvider extends Provider implements TranscriptionProvider
{
    use GeneratesTranscriptions;
    use HasTranscriptionGateway;

    public function __construct(
        protected array $config,
        protected Dispatcher $events,
    ) {}

    /**
     * Get the credentials for the underlying AI provider.
     */
    public function providerCredentials(): array
    {
        return array_filter([
            'access_key_id' => $this->config['access_key_id'] ?? null,
            'secret_access_key' => $this->config['secret_access_key'] ?? null,
            'session_token' => $this->config['session_token'] ?? null,
        ]);
    }

    /**
     * Get the provider connection configuration other than the driver, key, and name.
     */
    public function additionalConfiguration(): array
    {
        return [
            'region' => $this->config['region'] ?? 'ap-southeast-2',
            'bucket' => $this->config['bucket'] ?? null,
            'prefix' => trim($this->config['prefix'] ?? 'transcriptions', '/'),
            'language' => $this->config['language'] ?? 'en-AU',
            'use_default_credential_provider' => $this->config['use_default_credential_provider'] ?? true,
        ];
    }

    /**
     * Get the name of the default transcription (STT) model.
     */
    public function defaultTranscriptionModel(): string
    {
        return $this->config['models']['transcription']['default'] ?? 'standard';
    }

    /**
     * Get the provider's transcription gateway.
     */
    public function transcriptionGateway(): TranscriptionGateway
    {
        return $this->transcriptionGateway ??= new TranscribeGateway;
    }
}
```

Note: `TranscribeGateway` doesn't exist yet. Create a minimal placeholder so
this task's tests pass — Task 5 fills it in:

`src/TranscribeGateway.php` (placeholder for this task only):

```php
<?php

declare(strict_types=1);

namespace Clinically\AiTranscribe;

use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Responses\TranscriptionResponse;
use RuntimeException;

final class TranscribeGateway implements TranscriptionGateway
{
    /**
     * Generate text from the given audio.
     *
     * @param  array<string, mixed>  $providerOptions
     */
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        int $timeout = 30,
        array $providerOptions = [],
    ): TranscriptionResponse {
        throw new RuntimeException('Not implemented.');
    }
}
```

Gotcha: `makeProvider(['region' => null])` merges a literal `null`, so the
provider must use `??` (it does) — `array_merge` keeps the null, and `?? 'ap-southeast-2'`
recovers the default. The same applies to `language` and `prefix`. For `prefix`,
`trim(null)` would be a TypeError on PHP 8.4, hence `$this->config['prefix'] ?? 'transcriptions'`
runs **inside** `trim()` — keep that exact expression.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/TranscribeProviderTest.php`
Expected: 8 passed.

- [ ] **Step 5: Commit**

```bash
git add src/TranscribeProvider.php src/TranscribeGateway.php tests/Unit/TranscribeProviderTest.php
git commit -m "feat: add TranscribeProvider implementing the laravel/ai transcription contract"
```

---

### Task 5: TranscribeGateway — happy path

**Files:**
- Modify: `src/TranscribeGateway.php` (replace the placeholder entirely)
- Test: `tests/Feature/TranscribeGatewayTest.php`

AWS call order on the happy path —
S3: `PutObject`, `GetObject`, `DeleteObject`, `DeleteObject`.
Transcribe: `StartTranscriptionJob`, `GetTranscriptionJob` (IN_PROGRESS), `GetTranscriptionJob` (COMPLETED).

- [ ] **Step 1: Write the failing happy-path tests**

`tests/Feature/TranscribeGatewayTest.php`:

```php
<?php

declare(strict_types=1);

use Aws\Result;
use Clinically\AiTranscribe\TranscribeGateway;
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
    $gateway = new TranscribeGateway(
        mockedTranscribe(mockedHandler([], $c1)),
        mockedS3(mockedHandler([], $c2)),
    );

    $gateway->generateTranscription(
        makeProvider(['bucket' => null]),
        'standard',
        new Base64Audio(base64_encode('bytes'), 'audio/mpeg'),
    );
})->throws(InvalidArgumentException::class, 'bucket');
```

Note on the `requires a configured bucket` test: `mockedHandler([], $c1)` uses
undefined-by-reference variables — declare them first:

```php
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
```

Use this corrected version, not the shorthand above.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/TranscribeGatewayTest.php`
Expected: FAIL — placeholder gateway throws `RuntimeException: Not implemented.` (and the constructor takes no arguments yet, which is also a failure — both are expected).

- [ ] **Step 3: Write the full gateway (replace the placeholder file entirely)**

`src/TranscribeGateway.php`:

```php
<?php

declare(strict_types=1);

namespace Clinically\AiTranscribe;

use Aws\S3\S3Client;
use Aws\TranscribeService\TranscribeServiceClient;
use Clinically\AiTranscribe\Exceptions\TranscriptionJobFailedException;
use Clinically\AiTranscribe\Exceptions\TranscriptionTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TranscriptionResponse;
use Throwable;

final class TranscribeGateway implements TranscriptionGateway
{
    public function __construct(
        private ?TranscribeServiceClient $transcribeClient = null,
        private ?S3Client $s3Client = null,
    ) {}

    /**
     * Generate text from the given audio.
     *
     * @param  array<string, mixed>  $providerOptions
     */
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        int $timeout = 30,
        array $providerOptions = [],
    ): TranscriptionResponse {
        $config = $provider->additionalConfiguration();

        $bucket = $config['bucket'] ?? null;

        if (! is_string($bucket) || $bucket === '') {
            throw new InvalidArgumentException(
                'An S3 bucket must be configured for the aws-transcribe provider.'
            );
        }

        $jobName = (string) Str::uuid7();
        $prefix = $config['prefix'];
        $audioKey = "{$prefix}/{$jobName}.".$this->extensionFor($audio->mimeType());
        $transcriptKey = "{$prefix}/{$jobName}.json";

        $s3 = $this->s3($provider);
        $transcribe = $this->transcribe($provider);

        try {
            $this->uploadAudio($s3, $bucket, $audioKey, $audio);

            $transcribe->startTranscriptionJob($this->jobRequest(
                $jobName, $bucket, $audioKey, $transcriptKey, $config, $model, $language, $diarize, $providerOptions,
            ));

            $job = $this->waitForCompletion($transcribe, $jobName, $timeout);

            if (($job['TranscriptionJobStatus'] ?? null) === 'FAILED') {
                throw new TranscriptionJobFailedException($jobName, $job['FailureReason'] ?? 'Unknown failure.');
            }

            $result = $s3->getObject(['Bucket' => $bucket, 'Key' => $transcriptKey]);

            $parsed = (new TranscriptParser)->parse((string) $result['Body']);

            return new TranscriptionResponse(
                $parsed->text,
                $parsed->segments,
                new Usage,
                new Meta($provider->name(), $model),
            );
        } finally {
            $this->cleanup($s3, $bucket, [$audioKey, $transcriptKey]);
        }
    }

    /**
     * Upload the audio to S3 for the transcription job to consume.
     */
    protected function uploadAudio(S3Client $s3, string $bucket, string $key, TranscribableAudio $audio): void
    {
        $request = [
            'Bucket' => $bucket,
            'Key' => $key,
            'Body' => $audio->content(),
        ];

        if ($audio->mimeType() !== null) {
            $request['ContentType'] = $audio->mimeType();
        }

        $s3->putObject($request);
    }

    /**
     * Build the StartTranscriptionJob request.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $providerOptions
     * @return array<string, mixed>
     */
    protected function jobRequest(
        string $jobName,
        string $bucket,
        string $audioKey,
        string $transcriptKey,
        array $config,
        string $model,
        ?string $language,
        bool $diarize,
        array $providerOptions,
    ): array {
        $request = [
            'TranscriptionJobName' => $jobName,
            'LanguageCode' => $language ?? $config['language'],
            'Media' => ['MediaFileUri' => "s3://{$bucket}/{$audioKey}"],
            'OutputBucketName' => $bucket,
            'OutputKey' => $transcriptKey,
        ];

        if ($diarize) {
            $request['Settings'] = [
                'ShowSpeakerLabels' => true,
                'MaxSpeakerLabels' => 10,
            ];
        }

        if ($model !== 'standard') {
            $request['ModelSettings'] = ['LanguageModelName' => $model];
        }

        return array_replace_recursive($request, $providerOptions);
    }

    /**
     * Poll the job until it completes, fails, or the timeout elapses.
     *
     * @return array<string, mixed>
     */
    protected function waitForCompletion(TranscribeServiceClient $transcribe, string $jobName, int $timeout): array
    {
        $waited = 0;
        $interval = 2;

        while (true) {
            $job = $transcribe->getTranscriptionJob([
                'TranscriptionJobName' => $jobName,
            ])['TranscriptionJob'] ?? [];

            if (in_array($job['TranscriptionJobStatus'] ?? null, ['COMPLETED', 'FAILED'], true)) {
                return $job;
            }

            if ($waited >= $timeout) {
                $this->abandonJob($transcribe, $jobName);

                throw new TranscriptionTimedOutException($jobName, $timeout);
            }

            Sleep::for($interval)->seconds();

            $waited += $interval;
            $interval = min($interval * 2, 10);
        }
    }

    /**
     * Delete a timed-out job so it stops processing the audio.
     */
    protected function abandonJob(TranscribeServiceClient $transcribe, string $jobName): void
    {
        try {
            $transcribe->deleteTranscriptionJob(['TranscriptionJobName' => $jobName]);
        } catch (Throwable $e) {
            Log::warning('Failed to delete timed-out Amazon Transcribe job.', [
                'job' => $jobName,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Best-effort deletion of the transcription artifacts from S3.
     *
     * @param  array<int, string>  $keys
     */
    protected function cleanup(S3Client $s3, string $bucket, array $keys): void
    {
        foreach ($keys as $key) {
            try {
                $s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
            } catch (Throwable $e) {
                Log::warning('Failed to delete transcription artifact from S3.', [
                    'bucket' => $bucket,
                    'key' => $key,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Get (or lazily build) the Transcribe client for the provider.
     */
    protected function transcribe(TranscriptionProvider $provider): TranscribeServiceClient
    {
        return $this->transcribeClient ??= new TranscribeServiceClient($this->awsClientConfig($provider));
    }

    /**
     * Get (or lazily build) the S3 client for the provider.
     */
    protected function s3(TranscriptionProvider $provider): S3Client
    {
        return $this->s3Client ??= new S3Client($this->awsClientConfig($provider));
    }

    /**
     * Build AWS client configuration from the provider's connection config.
     *
     * @return array<string, mixed>
     */
    protected function awsClientConfig(TranscriptionProvider $provider): array
    {
        $config = $provider->additionalConfiguration();
        $credentials = $provider->providerCredentials();

        $clientConfig = [
            'region' => $config['region'],
            'version' => 'latest',
        ];

        if (isset($credentials['access_key_id'], $credentials['secret_access_key'])) {
            $clientConfig['credentials'] = [
                'key' => $credentials['access_key_id'],
                'secret' => $credentials['secret_access_key'],
                'token' => $credentials['session_token'] ?? null,
            ];
        }

        return $clientConfig;
    }

    /**
     * Map a MIME type to the S3 object extension Transcribe infers the format from.
     */
    protected function extensionFor(?string $mimeType): string
    {
        return match ($mimeType) {
            'audio/mp4', 'audio/m4a', 'audio/x-m4a' => 'mp4',
            'audio/wav', 'audio/x-wav', 'audio/wave' => 'wav',
            'audio/flac', 'audio/x-flac' => 'flac',
            'audio/ogg', 'application/ogg' => 'ogg',
            'audio/webm', 'video/webm' => 'webm',
            'audio/amr' => 'amr',
            default => 'mp3',
        };
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/TranscribeGatewayTest.php`
Expected: 5 passed.

- [ ] **Step 5: Run the whole suite (provider tests still green)**

Run: `vendor/bin/pest`
Expected: all passed.

- [ ] **Step 6: Commit**

```bash
git add src/TranscribeGateway.php tests/Feature/TranscribeGatewayTest.php
git commit -m "feat: implement Amazon Transcribe batch gateway with ephemeral S3 lifecycle"
```

---

### Task 6: Gateway failure modes

**Files:**
- Modify: `tests/Feature/TranscribeGatewayTest.php` (append tests)

The implementation from Task 5 should already handle these — this task proves
it. If a test fails, fix the gateway, don't weaken the test.

- [ ] **Step 1: Append the failure-mode tests**

Append to `tests/Feature/TranscribeGatewayTest.php`:

```php
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
```

Add these imports to the top of the test file:

```php
use Aws\Command;
use Aws\S3\Exception\S3Exception;
use Clinically\AiTranscribe\Exceptions\TranscriptionJobFailedException;
use Clinically\AiTranscribe\Exceptions\TranscriptionTimedOutException;
use Illuminate\Support\Facades\Log;
```

Note on the MockHandler + exception entries: `MockHandler::append()` accepts
`Aws\Exception\AwsException` instances directly (it throws them when that queue
slot is reached) — but our `mockedHandler()` helper wraps every entry in a
recording closure that *returns* the entry. Returning an exception from a
MockHandler callable also makes the SDK throw it, and the command name is still
recorded first. No helper change is needed.

- [ ] **Step 2: Run the tests**

Run: `vendor/bin/pest tests/Feature/TranscribeGatewayTest.php`
Expected: 8 passed. If `does not mask the result when cleanup fails` errors
because a returned exception isn't thrown by the installed SDK version, change
those two queue entries to callables that record then throw:

```php
// replacement entries if needed:
function (Aws\CommandInterface $command) { throw new S3Exception('Access denied.', $command); },
```

(If you make this change, append them with `$handler->append(...)` after building
`mockedHandler` for the first two results, and drop the two exception entries
from the `mockedHandler` array.)

- [ ] **Step 3: Run the whole suite**

Run: `vendor/bin/pest`
Expected: all passed.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/TranscribeGatewayTest.php
git commit -m "test: cover job failure, polling timeout, and cleanup resilience"
```

---

### Task 7: ServiceProvider + driver registration

**Files:**
- Create: `src/AiTranscribeServiceProvider.php`
- Test: `tests/Feature/DriverRegistrationTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/DriverRegistrationTest.php`:

```php
<?php

declare(strict_types=1);

use Aws\Result;
use Clinically\AiTranscribe\TranscribeGateway;
use Clinically\AiTranscribe\TranscribeProvider;
use Illuminate\Support\Sleep;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\TranscriptionGenerated;
use Laravel\Ai\Files\Base64Audio;

it('resolves the aws-transcribe driver from the ai manager', function () {
    $provider = Ai::transcriptionProvider('aws');

    expect($provider)->toBeInstanceOf(TranscribeProvider::class)
        ->and($provider->name())->toBe('aws')
        ->and($provider->defaultTranscriptionModel())->toBe('standard');
});

it('is the default transcription provider', function () {
    expect(Ai::transcriptionProvider())->toBeInstanceOf(TranscribeProvider::class);
});

it('transcribes end to end through the sdk provider', function () {
    Sleep::fake();

    Event::fake([TranscriptionGenerated::class]);

    $transcriptJson = file_get_contents(__DIR__.'/../fixtures/transcript-diarized.json');

    $s3Commands = [];
    $s3 = mockedS3(mockedHandler([
        new Result([]),
        new Result(['Body' => $transcriptJson]),
        new Result([]),
        new Result([]),
    ], $s3Commands));

    $transcribeCommands = [];
    $transcribe = mockedTranscribe(mockedHandler([
        new Result([]), // StartTranscriptionJob
        new Result(['TranscriptionJob' => ['TranscriptionJobStatus' => 'COMPLETED']]), // GetTranscriptionJob
    ], $transcribeCommands));

    $provider = Ai::transcriptionProvider('aws');
    $provider->useTranscriptionGateway(new TranscribeGateway($transcribe, $s3));

    $response = $provider->transcribe(
        new Base64Audio(base64_encode('fake-audio'), 'audio/mpeg'),
        diarize: true,
    );

    expect((string) $response)->toBe('Hello there. Hi, how are you?')
        ->and($response->segments)->toHaveCount(2);

    Event::assertDispatched(TranscriptionGenerated::class);
});
```

Add `use Illuminate\Support\Facades\Event;` to the imports.

Gotcha: `$provider->transcribe(...)` dispatches real events via the provider's
injected dispatcher, which is the container's event dispatcher — `Event::fake`
swaps the container binding, but the provider captured the dispatcher at
construction time. Since `Ai::transcriptionProvider('aws')` constructs the
provider lazily on first call, call `Event::fake()` **before** the first
`Ai::transcriptionProvider('aws')` in that test (as written above). If the
assertion still fails because a previous test in the same file already resolved
and cached the instance, use `Ai::forgetInstances()` if available, or simply
drop the `Event::fake`/`assertDispatched` lines — event dispatch is the SDK's
behaviour, not ours. Do not add complexity to make it pass.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/DriverRegistrationTest.php`
Expected: FAIL — `AiTranscribeServiceProvider` class not found (TestCase references it).

- [ ] **Step 3: Write the service provider**

`src/AiTranscribeServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Clinically\AiTranscribe;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Ai;

final class AiTranscribeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! class_exists(Ai::class)) {
            return;
        }

        Ai::extend('aws-transcribe', fn (Application $app, array $config) => new TranscribeProvider(
            $config,
            $app->make(Dispatcher::class),
        ));
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/DriverRegistrationTest.php`
Expected: 3 passed.

- [ ] **Step 5: Run the whole suite**

Run: `vendor/bin/pest`
Expected: all passed.

- [ ] **Step 6: Commit**

```bash
git add src/AiTranscribeServiceProvider.php tests/Feature/DriverRegistrationTest.php
git commit -m "feat: register the aws-transcribe driver with the laravel/ai manager"
```

---

### Task 8: Static analysis + formatting

**Files:**
- Possibly modify: any `src/` file PHPStan or Pint flags

- [ ] **Step 1: Run Pint**

Run: `composer format`
Expected: formats files; re-run `vendor/bin/pest` afterwards — all passed.

- [ ] **Step 2: Run PHPStan**

Run: `composer analyse`
Expected: 0 errors. Likely flags and their fixes:

- `Cannot access offset ... on mixed` in the gateway around `$config['...']` /
  `$job['...']` — add `@var`-free narrowing (e.g. `is_string()`/`is_array()`
  guards) or precise array shapes in the method docblocks:

  ```php
  /**
   * @return array{region: string, bucket: ?string, prefix: string, language: string, use_default_credential_provider: bool}
   */
  public function additionalConfiguration(): array
  ```

  on `TranscribeProvider`, and in the gateway annotate
  `$job` as `@var array<string, mixed>`.
- `Result` `ArrayAccess` offsets returning `mixed`: cast explicitly, e.g.
  `(string) $result['Body']` is already in place; if `$transcribe->getTranscriptionJob(...)['TranscriptionJob']`
  is flagged, assign the result first and guard with `is_array()`.
- If a vendor-trait error from `GeneratesTranscriptions` appears (it's analysed
  as part of our class), add a **specific** ignore to `phpstan.neon` with a
  comment — never a blanket ignore.

- [ ] **Step 3: Run the whole suite once more**

Run: `vendor/bin/pest`
Expected: all passed.

- [ ] **Step 4: Commit (only if files changed)**

```bash
git add -A
git commit -m "chore: satisfy phpstan level 6 and pint"
```

---

### Task 9: Documentation (README, CLAUDE.md, Boost guidelines)

**Files:**
- Create: `README.md`
- Create: `CLAUDE.md`
- Create: `resources/boost/guidelines/core.blade.php`

- [ ] **Step 1: Write `README.md`**

```markdown
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
$response = Transcription::fromPath($path)->diarized()->generate();

foreach ($response->segments as $segment) {
    echo "{$segment->speaker}: {$segment->text}\n";
}
```

Custom language models and any other `StartTranscriptionJob` options pass
through `withProviderOptions()` (or set a default model in config):

```php
'models' => ['transcription' => ['default' => 'my-clinical-clm']],
```

```php
Transcription::fromPath($path)
    ->withProviderOptions([
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
```

Note: verify the fluent method names used in the README (`diarized()`,
`withProviderOptions()`, `generate()`) against
`vendor/laravel/ai/src/PendingResponses/PendingTranscriptionGeneration.php`
after `composer install`, and correct the README if they differ. Do not guess.

- [ ] **Step 2: Write `CLAUDE.md`**

```markdown
# clinically/laravel-ai-transcribe

Amazon Transcribe (batch) driver for the official `laravel/ai` SDK.

## Architecture

- `AiTranscribeServiceProvider` registers the `aws-transcribe` driver via
  `Ai::extend()`. No config file, no migrations, no views — connection config
  lives in the consuming app's `config/ai.php`.
- `TranscribeProvider` implements `Laravel\Ai\Contracts\Providers\TranscriptionProvider`
  using the SDK's `GeneratesTranscriptions` + `HasTranscriptionGateway` traits.
  It mirrors the SDK's `BedrockProvider` constructor shape
  `(array $config, Dispatcher $events)`.
- `TranscribeGateway` implements `Laravel\Ai\Contracts\Gateway\TranscriptionGateway`:
  upload audio to S3 → `StartTranscriptionJob` → poll with capped backoff →
  parse transcript JSON → **always** delete both S3 objects in `finally`.
  Timeout also issues `DeleteTranscriptionJob`.
- `TranscriptParser` is pure: Transcribe result JSON → `ParsedTranscript`
  (text + `TranscriptionSegment` collection from `results.audio_segments`).

## Invariants

- S3 objects are ephemeral. Never persist audio or transcripts in the bucket.
- `model === 'standard'` means no `ModelSettings`; any other model name is sent
  as a custom language model (`ModelSettings.LanguageModelName`).
- `$providerOptions` are merged into the job request **last** via
  `array_replace_recursive` — they win over everything.
- Cleanup failures log warnings; they never mask the transcription result or error.

## Commands

- `composer test` — Pest
- `composer analyse` — PHPStan level 6
- `composer format` — Pint

## Testing conventions

AWS calls are mocked with `Aws\MockHandler` via helpers in `tests/Pest.php`
(`mockedHandler`, `mockedS3`, `mockedTranscribe`, `makeProvider`). `Sleep::fake()`
keeps polling tests instant. No network access in tests, ever.
```

- [ ] **Step 3: Write `resources/boost/guidelines/core.blade.php`**

```blade
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
```

- [ ] **Step 4: Run the full suite, analysis, and formatting one final time**

Run: `vendor/bin/pest && composer analyse && composer format --test`
Expected: all green. (`pint --test` verifies formatting without changing files.)

- [ ] **Step 5: Commit**

```bash
git add README.md CLAUDE.md resources/
git commit -m "docs: add README, CLAUDE.md, and Boost guidelines"
```

---

## Spec coverage check

| Spec requirement | Task |
| --- | --- |
| `Ai::extend('aws-transcribe', ...)` registration, guarded | 7 |
| `TranscribeProvider` (credentials, regional config, default model) | 4 |
| `TranscribeGateway` upload → job → poll → parse → cleanup | 5 |
| Diarization → speaker segments | 3, 5 |
| `providerOptions` merged last (CLM, redaction) | 5 |
| Custom language model via non-standard model name | 5 |
| Ephemeral S3 (`finally` cleanup, best-effort, logged) | 5, 6 |
| Timeout → `DeleteTranscriptionJob` + `TranscriptionTimedOutException` | 5, 6 |
| `FAILED` → `TranscriptionJobFailedException` with reason | 2, 6 |
| Empty `Usage`, `Meta(provider, model)` | 5 |
| Pest + Testbench, MockHandler tests, fixtures | 1, 3, 5, 6, 7 |
| PHPStan level 6 + Pint | 8 |
| README / CLAUDE.md / Boost guidelines | 9 |
