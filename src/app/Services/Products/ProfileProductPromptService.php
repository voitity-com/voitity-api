<?php

namespace App\Services\Products;

use App\Enums\ProfileProductStatus;
use App\Models\Profile;
use App\Models\ProfileProduct;
use App\Services\Features\FeatureService;

class ProfileProductPromptService
{
    public function __construct(
        private readonly FeatureService $features,
        private readonly ProfileProductImageService $images,
        private readonly ProfileProductLinkService $links
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function productsForPrompt(Profile $profile): array
    {
        if (! $this->features->isProfileFeatureEnabled($profile, FeatureService::PRODUCTS)) {
            return [];
        }

        if (! $profile->products_enabled) {
            return [];
        }

        return $profile->products()
            ->where('status', ProfileProductStatus::Published->value)
            ->latest('published_at')
            ->limit(max(1, (int) config('products.prompt_limit', 15)))
            ->get()
            ->map(fn (ProfileProduct $product): array => $this->toPayload($product))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $ids
     * @param  array<int, array<string, mixed>>  $available
     * @return array<int, array<string, mixed>>
     */
    public function payloadForIds(array $ids, array $available): array
    {
        $wanted = collect($ids)->map(fn ($id): int => (int) $id)->filter()->unique()->values();

        return collect($available)
            ->filter(fn (array $product): bool => $wanted->contains((int) ($product['id'] ?? 0)))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(ProfileProduct $product): array
    {
        $product->loadMissing('profile');

        return [
            'id' => $product->id,
            'public_id' => $product->public_id,
            'name' => $product->name,
            'description' => $product->description,
            'image_url' => $this->images->imageUrl($product),
            'destination_type' => $product->destination_type->value,
            'public_url' => $this->links->publicUrl($product),
            'action_url' => $this->links->actionUrl($product),
        ];
    }
}
