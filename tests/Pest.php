<?php

declare(strict_types=1);

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\ResultInterface;
use Aws\S3\S3Client;
use Aws\TranscribeService\TranscribeServiceClient;
use Clinically\AiTranscribe\Tests\TestCase;
use Clinically\AiTranscribe\TranscribeProvider;

uses(TestCase::class)->in('Unit', 'Feature');

/**
 * Build a MockHandler that records each command's name and params
 * into $commands and returns the queued results in order.
 *
 * @param  array<int, ResultInterface|AwsException>  $results
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
