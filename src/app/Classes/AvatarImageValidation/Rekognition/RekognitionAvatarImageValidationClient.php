<?php

namespace App\Classes\AvatarImageValidation\Rekognition;

use App\Classes\AvatarImageValidation\AvatarImageAnalysis;
use App\Classes\AvatarImageValidation\AvatarImageValidationClient;
use App\Exceptions\Avatar\AvatarImageValidationUnavailableException;
use Aws\Exception\AwsException;
use Aws\Rekognition\RekognitionClient;
use Illuminate\Support\Facades\Log;
use Throwable;

class RekognitionAvatarImageValidationClient implements AvatarImageValidationClient
{
    public function __construct(private readonly RekognitionClient $client) {}

    public function analyze(string $imageBytes): AvatarImageAnalysis
    {
        try {
            $result = $this->client->detectFaces([
                'Attributes' => ['ALL'],
                'Image' => ['Bytes' => $imageBytes],
            ]);

            return new AvatarImageAnalysis(
                faces: array_values($result->get('FaceDetails') ?? []),
                requestId: $result->get('@metadata')['headers']['x-amzn-requestid'] ?? null,
            );
        } catch (AwsException $exception) {
            Log::error('Avatar image validation provider request failed.', [
                'provider' => $this->name(),
                'aws_error_code' => $exception->getAwsErrorCode(),
                'aws_error_type' => $exception->getAwsErrorType(),
                'status_code' => $exception->getStatusCode(),
                'request_id' => $exception->getAwsRequestId(),
            ]);

            throw new AvatarImageValidationUnavailableException(
                'The avatar image validation service is temporarily unavailable.',
                previous: $exception,
            );
        } catch (Throwable $exception) {
            Log::error('Avatar image validation provider failed unexpectedly.', [
                'provider' => $this->name(),
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw new AvatarImageValidationUnavailableException(
                'The avatar image validation service is temporarily unavailable.',
                previous: $exception,
            );
        }
    }

    public function name(): string
    {
        return 'aws_rekognition';
    }
}
