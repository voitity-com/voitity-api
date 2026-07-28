<?php

namespace App\Http\Responses\Products;

use App\Models\ProfileProduct;
use App\Services\Products\ProfileProductImageService;
use App\Services\Products\ProfileProductLinkService;

class ProfileProductResponse
{
    public function __construct(private readonly ProfileProduct $product) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $this->product->loadMissing('profile');
        $images = app(ProfileProductImageService::class);
        $links = app(ProfileProductLinkService::class);

        return [
            'id' => $this->product->id,
            'public_id' => $this->product->public_id,
            'external_id' => $this->product->external_id,
            'slug' => $this->product->slug,
            'name' => $this->product->name,
            'description' => $this->product->description,
            'image_source' => $this->product->image_source,
            'image_url' => $images->imageUrl($this->product),
            'destination_type' => $this->product->destination_type->value,
            'destination_url' => $this->product->destination_url,
            'country_code' => $this->product->country_code,
            'phone_number' => $this->product->phone_number,
            'status' => $this->product->status->value,
            'public_url' => $links->publicUrl($this->product),
            'action_url' => $links->actionUrl($this->product),
            'message_preview' => $links->message($this->product),
            'published_at' => $this->product->published_at?->toJSON(),
            'created_at' => $this->product->created_at?->toJSON(),
            'updated_at' => $this->product->updated_at?->toJSON(),
        ];
    }
}
