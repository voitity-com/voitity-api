<?php

namespace App\Classes\Subscriptions;

use App\Enums\AvatarVariant;
use App\Enums\ProfileDomainStatus;
use App\Enums\ProfileProductStatus;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use App\Models\ProfileIntegration;
use App\Models\Subscription;
use Illuminate\Support\Collection;

class FreePlanDataReconciler
{
    private const METADATA_KEY = '_free_plan';

    public function apply(Subscription $subscription): void
    {
        $profiles = Profile::query()
            ->where('user_id', $subscription->user_id)
            ->with(['appearance', 'avatars', 'domain', 'integrations', 'products', 'sources'])
            ->get();

        foreach ($profiles as $profile) {
            $this->useIncludedTemplate($profile);
            $this->useStaticAvatar($profile);
            $this->limitIntegrations($profile);
            $this->limitProducts($profile);
            $this->limitSources($profile);
            $this->disableCustomDomain($profile);
        }
    }

    public function restore(Subscription $subscription): void
    {
        $profiles = Profile::query()
            ->where('user_id', $subscription->user_id)
            ->with(['appearance', 'avatars', 'domain', 'integrations', 'products', 'sources'])
            ->get();

        foreach ($profiles as $profile) {
            $appearance = $profile->appearance;
            $appearanceState = data_get($appearance?->metadata, self::METADATA_KEY);

            if ($appearance && is_array($appearanceState) && ($appearanceState['locked'] ?? false)) {
                $metadata = $appearance->metadata ?? [];
                unset($metadata[self::METADATA_KEY]);
                $appearance->forceFill([
                    'template_key' => (string) ($appearanceState['previous_template_key'] ?? 'profile01'),
                    'metadata' => $metadata,
                ])->save();
            }

            foreach ($profile->avatars as $avatar) {
                $state = data_get($avatar->metadata, self::METADATA_KEY);

                if (! is_array($state) || ! ($state['locked'] ?? false)) {
                    continue;
                }

                $metadata = $avatar->metadata ?? [];
                unset($metadata[self::METADATA_KEY]);
                $previousVariant = AvatarVariant::tryFrom((string) ($state['previous_variant'] ?? ''));
                $avatar->forceFill([
                    'file' => $state['previous_file'] ?? $avatar->original_file,
                    'selected_variant' => $previousVariant ?? AvatarVariant::Original,
                    'video_duration_seconds' => max(0, (int) ($state['previous_video_duration_seconds'] ?? 0)),
                    'metadata' => $metadata,
                ])->save();
            }

            foreach ($profile->integrations as $integration) {
                $state = data_get($integration->metadata, self::METADATA_KEY);

                if (! is_array($state) || ! ($state['locked'] ?? false)) {
                    continue;
                }

                $metadata = $integration->metadata ?? [];
                unset($metadata[self::METADATA_KEY]);
                $integration->forceFill([
                    'status' => (string) ($state['previous_status'] ?? ProfileIntegration::STATUS_CONNECTED),
                    'metadata' => $metadata,
                ])->save();
            }

            foreach ($profile->products as $product) {
                $state = data_get($product->metadata, self::METADATA_KEY);

                if (! is_array($state) || ! ($state['locked'] ?? false)) {
                    continue;
                }

                $metadata = $product->metadata ?? [];
                unset($metadata[self::METADATA_KEY]);
                $product->forceFill([
                    'status' => (string) ($state['previous_status'] ?? ProfileProductStatus::Draft->value),
                    'metadata' => $metadata,
                ])->save();
            }

            foreach ($profile->sources as $source) {
                $state = data_get($source->metadata, self::METADATA_KEY);

                if (! is_array($state) || ! ($state['locked'] ?? false)) {
                    continue;
                }

                $metadata = $source->metadata ?? [];
                unset($metadata[self::METADATA_KEY]);
                $source->forceFill(['metadata' => $metadata])->save();
            }

            $domain = $profile->domain;

            if ($domain && $domain->last_error_code === 'free_plan_locked') {
                $domain->forceFill([
                    'status' => filled($domain->provider_tenant_id)
                        ? ProfileDomainStatus::PendingDns->value
                        : ProfileDomainStatus::PendingProvisioning->value,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ])->save();
            }
        }
    }

    private function useIncludedTemplate(Profile $profile): void
    {
        $appearance = $profile->appearance;

        if (! $appearance || $appearance->template_key === 'profile01') {
            return;
        }

        $metadata = $appearance->metadata ?? [];

        if (! data_get($metadata, self::METADATA_KEY.'.locked', false)) {
            $metadata[self::METADATA_KEY] = [
                'locked' => true,
                'previous_template_key' => $appearance->template_key,
            ];
        }

        $appearance->forceFill([
            'template_key' => 'profile01',
            'metadata' => $metadata,
        ])->save();
    }

    private function useStaticAvatar(Profile $profile): void
    {
        foreach ($profile->avatars->where('status', ProfileAvatar::STATUS_ACTIVE) as $avatar) {
            if (! filled($avatar->original_file)) {
                continue;
            }

            $metadata = $avatar->metadata ?? [];

            if (! data_get($metadata, self::METADATA_KEY.'.locked', false)) {
                $selectedVariant = $avatar->selected_variant instanceof AvatarVariant
                    ? $avatar->selected_variant->value
                    : (string) $avatar->selected_variant;
                $metadata[self::METADATA_KEY] = [
                    'locked' => true,
                    'previous_file' => $avatar->file,
                    'previous_variant' => $selectedVariant,
                    'previous_video_duration_seconds' => (int) $avatar->video_duration_seconds,
                ];
            }

            $avatar->forceFill([
                'file' => $avatar->original_file,
                'selected_variant' => AvatarVariant::Original,
                'video_duration_seconds' => 0,
                'metadata' => $metadata,
            ])->save();
        }
    }

    private function limitIntegrations(Profile $profile): void
    {
        $unlocked = $this->unlocked($profile->integrations)
            ->sortByDesc(fn ($integration): string => sprintf(
                '%d-%020d',
                $integration->status === ProfileIntegration::STATUS_CONNECTED ? 1 : 0,
                $integration->id,
            ))
            ->values();

        foreach ($unlocked as $integration) {
            $integration->forceFill([
                'status' => ProfileIntegration::STATUS_REVOKED,
                'metadata' => [
                    ...($integration->metadata ?? []),
                    self::METADATA_KEY => [
                        'locked' => true,
                        'previous_status' => $integration->status,
                    ],
                ],
            ])->save();
        }
    }

    private function limitProducts(Profile $profile): void
    {
        $unlocked = $this->unlocked($profile->products)
            ->sortByDesc(fn ($product): string => sprintf(
                '%d-%020d',
                $product->status === ProfileProductStatus::Published ? 1 : 0,
                $product->id,
            ))
            ->values();

        foreach ($unlocked->slice(1) as $product) {
            $previousStatus = $product->status instanceof ProfileProductStatus
                ? $product->status->value
                : (string) $product->status;
            $product->forceFill([
                'status' => ProfileProductStatus::Draft->value,
                'metadata' => [
                    ...($product->metadata ?? []),
                    self::METADATA_KEY => [
                        'locked' => true,
                        'previous_status' => $previousStatus,
                    ],
                ],
            ])->save();
        }
    }

    private function limitSources(Profile $profile): void
    {
        $unlocked = $this->unlocked($profile->sources)
            ->sortByDesc(fn ($source): string => sprintf(
                '%020d-%020d',
                $source->updated_at?->getTimestamp() ?? 0,
                $source->id,
            ))
            ->values();

        foreach ($unlocked->slice(1) as $source) {
            $source->forceFill([
                'metadata' => [
                    ...($source->metadata ?? []),
                    self::METADATA_KEY => ['locked' => true],
                ],
            ])->save();
        }
    }

    private function disableCustomDomain(Profile $profile): void
    {
        $domain = $profile->domain;

        if (! $domain || $domain->status === ProfileDomainStatus::Disconnecting) {
            return;
        }

        $domain->forceFill([
            'status' => ProfileDomainStatus::Failed->value,
            'last_error_code' => 'free_plan_locked',
            'last_error_message' => 'Custom domains are disabled while the Free plan is active.',
        ])->save();
    }

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param  Collection<TKey, TValue>  $items
     * @return Collection<TKey, TValue>
     */
    private function unlocked(Collection $items): Collection
    {
        return $items->reject(fn ($item): bool => (bool) data_get($item->metadata, self::METADATA_KEY.'.locked', false));
    }
}
