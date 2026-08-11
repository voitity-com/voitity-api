<?php

namespace App\Http\Responses\Profile;

use App\Enums\ProfileStatus;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use App\Models\ProfileWidget;
use Illuminate\Support\Facades\Storage;

class ProfileWidgetResponse
{
    public function __construct(private readonly ProfileWidget $widget) {}

    public function toArray(): array
    {
        $profile = $this->profile();

        return [
            'enabled' => (bool) $this->widget->enabled,
            'public_key' => $this->widget->public_key,
            'available' => $this->isAvailable($profile),
            'profile_active' => (bool) $profile->active,
            'profile_status' => $profile->status?->value,
            'launcher_label' => $this->launcherLabel($profile),
            'avatar_url' => $this->avatarImageUrl($profile),
            'created_at' => $this->widget->created_at?->toJSON(),
            'updated_at' => $this->widget->updated_at?->toJSON(),
        ];
    }

    public function toPublicArray(): array
    {
        $profile = $this->profile();

        return [
            'public_key' => $this->widget->public_key,
            'profile' => [
                'id' => $profile->id,
                'alias' => $profile->alias,
                'name' => $profile->name,
                'locale' => $profile->locale === 'en' ? 'en' : 'es',
            ],
            'launcher' => [
                'label' => $this->launcherLabel($profile),
                'avatar_url' => $this->avatarImageUrl($profile),
            ],
        ];
    }

    private function profile(): Profile
    {
        /** @var Profile $profile */
        $profile = $this->widget->profile;
        $profile->loadMissing([
            'avatars' => fn ($query) => $query
                ->where('status', ProfileAvatar::STATUS_ACTIVE)
                ->with('aiImage')
                ->orderByDesc('updated_at'),
        ]);

        return $profile;
    }

    private function isAvailable(Profile $profile): bool
    {
        return (bool) $this->widget->enabled
            && (bool) $profile->active
            && $profile->status === ProfileStatus::Published;
    }

    private function launcherLabel(Profile $profile): string
    {
        return $profile->locale === 'en' ? 'Talk to me' : 'Habla conmigo';
    }

    private function avatarImageUrl(Profile $profile): ?string
    {
        /** @var ProfileAvatar|null $avatar */
        $avatar = $profile->avatars->first();

        if (! $avatar) {
            return null;
        }

        foreach ([$avatar->file, $avatar->aiImage?->file, $avatar->original_file] as $candidate) {
            if (! is_string($candidate) || ! $this->isImageFile($candidate)) {
                continue;
            }

            if ($this->isPublicHttpUrl($candidate)) {
                return $candidate;
            }

            return Storage::disk((string) config('videoai.profiles.disk', 'profiles'))->url($candidate);
        }

        return null;
    }

    private function isImageFile(string $file): bool
    {
        $path = parse_url($file, PHP_URL_PATH);
        $extension = strtolower(pathinfo(is_string($path) ? $path : $file, PATHINFO_EXTENSION));

        return in_array($extension, ['avif', 'gif', 'jpeg', 'jpg', 'png', 'webp'], true);
    }

    private function isPublicHttpUrl(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
