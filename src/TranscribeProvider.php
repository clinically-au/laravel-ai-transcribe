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

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config,
        protected Dispatcher $events,
    ) {}

    /**
     * Get the credentials for the underlying AI provider.
     *
     * @return array<string, string>
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
     *
     * @return array{
     *     region: string,
     *     bucket: ?string,
     *     prefix: string,
     *     language: string,
     *     use_default_credential_provider: bool
     * }
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
