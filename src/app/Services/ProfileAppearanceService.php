<?php

namespace App\Services;

use App\Models\Profile;
use App\Models\ProfileAppearance;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProfileAppearanceService
{
    public function ensureForProfile(Profile $profile): ProfileAppearance
    {
        return ProfileAppearance::query()->firstOrCreate([
            'profile_id' => $profile->id,
        ], [
            'template_key' => 'profile01',
            'background_type' => ProfileAppearance::BACKGROUND_CSS,
        ]);
    }

    public function update(Profile $profile, array $attributes): ProfileAppearance
    {
        $appearance = $this->ensureForProfile($profile);
        $appearance->fill($attributes);
        $appearance->save();

        return $appearance->refresh();
    }

    public function replaceBackgroundImage(
        Profile $profile,
        UploadedFile $image,
        ?string $templateKey = null,
    ): ProfileAppearance {
        $appearance = $this->ensureForProfile($profile);
        $diskName = (string) config('profile-appearance.disk', 'profiles');
        $visibility = (string) config('profile-appearance.visibility', 'public');
        $extension = strtolower($image->guessExtension() ?: $image->extension() ?: 'jpg');
        $directory = "profiles/{$profile->id}/backgrounds";
        $filename = Str::uuid().'.'.$extension;

        $storedPath = Storage::disk($diskName)->putFileAs(
            $directory,
            $image,
            $filename,
            ['visibility' => $visibility],
        );

        if (! is_string($storedPath) || $storedPath === '') {
            throw new RuntimeException('The profile background image could not be stored.');
        }

        $previousDisk = $appearance->background_image_disk;
        $previousPath = $appearance->background_image_path;

        try {
            DB::transaction(function () use ($appearance, $diskName, $storedPath, $templateKey): void {
                $appearance->forceFill([
                    'background_type' => ProfileAppearance::BACKGROUND_IMAGE,
                    'background_image_disk' => $diskName,
                    'background_image_path' => $storedPath,
                    ...($templateKey ? ['template_key' => $templateKey] : []),
                ])->save();
            });
        } catch (Throwable $exception) {
            Storage::disk($diskName)->delete($storedPath);

            throw $exception;
        }

        if (filled($previousDisk) && filled($previousPath) && $previousPath !== $storedPath) {
            Storage::disk($previousDisk)->delete($previousPath);
        }

        return $appearance->refresh();
    }
}
