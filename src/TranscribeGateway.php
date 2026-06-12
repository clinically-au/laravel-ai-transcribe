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
