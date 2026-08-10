<?php

namespace Tests\Unit\Classes\AvatarImageValidation;

use App\Classes\AvatarImageValidation\AvatarImageAnalysis;
use App\Classes\AvatarImageValidation\AvatarImageValidationClient;
use App\Classes\AvatarImageValidation\AvatarImageValidator;
use App\Exceptions\Avatar\InvalidAvatarSourceImageException;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AvatarImageValidatorTest extends TestCase
{
    #[Test]
    public function it_skips_the_provider_in_the_local_environment(): void
    {
        config()->set('app.env', 'local');

        $client = new class implements AvatarImageValidationClient
        {
            public int $calls = 0;

            public function analyze(string $imageBytes): AvatarImageAnalysis
            {
                $this->calls++;

                throw new \RuntimeException('The provider must not be called locally.');
            }

            public function name(): string
            {
                return 'fake_rekognition';
            }
        };

        $result = (new AvatarImageValidator($client))->validate($this->imageUpload());

        $this->assertTrue($result->valid);
        $this->assertSame([], $result->reasonCodes);
        $this->assertSame(0, $client->calls);
        $this->assertSame([
            'validation_skipped' => true,
            'environment' => 'local',
        ], $result->summary);
    }

    #[Test]
    public function it_accepts_one_clear_centered_face(): void
    {
        $result = $this->validatorWithFaces([$this->validFace()])->validate($this->imageUpload());

        $this->assertTrue($result->valid);
        $this->assertSame([], $result->reasonCodes);
        $this->assertSame(1, $result->summary['face_count']);
    }

    #[Test]
    public function it_rejects_images_without_a_face(): void
    {
        try {
            $this->validatorWithFaces([])->validate($this->imageUpload(), 'es');
            $this->fail('The image should have been rejected.');
        } catch (InvalidAvatarSourceImageException $exception) {
            $this->assertSame(['no_face'], $exception->validationResult()->reasonCodes);
            $this->assertStringContainsString('No se detectó un rostro', $exception->getMessage());
        }
    }

    #[Test]
    public function it_rejects_images_with_more_than_one_face(): void
    {
        try {
            $this->validatorWithFaces([$this->validFace(), $this->validFace()])->validate($this->imageUpload());
            $this->fail('The image should have been rejected.');
        } catch (InvalidAvatarSourceImageException $exception) {
            $this->assertSame(['multiple_faces'], $exception->validationResult()->reasonCodes);
        }
    }

    #[Test]
    public function it_reports_all_relevant_face_quality_failures(): void
    {
        $face = $this->validFace();
        $face['BoundingBox'] = ['Width' => 0.1, 'Height' => 0.1, 'Left' => 0.84, 'Top' => 0.75];
        $face['Quality'] = ['Brightness' => 10, 'Sharpness' => 15];
        $face['Pose'] = ['Yaw' => 40, 'Pitch' => 0, 'Roll' => 0];
        $face['FaceOccluded'] = ['Value' => true, 'Confidence' => 99];
        $face['EyesOpen'] = ['Value' => false, 'Confidence' => 99];
        $face['Sunglasses'] = ['Value' => true, 'Confidence' => 99];

        try {
            $this->validatorWithFaces([$face])->validate($this->imageUpload(), 'en');
            $this->fail('The image should have been rejected.');
        } catch (InvalidAvatarSourceImageException $exception) {
            $this->assertEqualsCanonicalizing([
                'face_too_small',
                'face_off_center',
                'low_sharpness',
                'poor_lighting',
                'pose_too_large',
                'face_occluded',
                'eyes_closed',
                'sunglasses',
            ], $exception->validationResult()->reasonCodes);
        }
    }

    private function validatorWithFaces(array $faces): AvatarImageValidator
    {
        $client = new class($faces) implements AvatarImageValidationClient
        {
            public function __construct(private readonly array $faces) {}

            public function analyze(string $imageBytes): AvatarImageAnalysis
            {
                return new AvatarImageAnalysis($this->faces, 'rekognition-test-request');
            }

            public function name(): string
            {
                return 'fake_rekognition';
            }
        };

        return new AvatarImageValidator($client);
    }

    private function validFace(): array
    {
        return [
            'BoundingBox' => ['Width' => 0.4, 'Height' => 0.5, 'Left' => 0.3, 'Top' => 0.2],
            'Confidence' => 99.9,
            'Quality' => ['Brightness' => 70, 'Sharpness' => 80],
            'Pose' => ['Yaw' => 0, 'Pitch' => 0, 'Roll' => 0],
            'FaceOccluded' => ['Value' => false, 'Confidence' => 99],
            'EyesOpen' => ['Value' => true, 'Confidence' => 99],
            'Sunglasses' => ['Value' => false, 'Confidence' => 99],
        ];
    }

    private function imageUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'avatar_validation_');
        $image = imagecreatetruecolor(1024, 1024);
        imagefill($image, 0, 0, imagecolorallocate($image, 230, 230, 230));
        imagejpeg($image, $path, 90);
        imagedestroy($image);

        return new UploadedFile($path, 'avatar.jpg', 'image/jpeg', null, true);
    }
}
