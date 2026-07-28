<?php

namespace App\Services\Products;

use App\Enums\ProfileProductDestinationType;
use App\Models\ProfileProduct;
use Illuminate\Support\Str;

class ProfileProductLinkService
{
    public function publicUrl(ProfileProduct $product): string
    {
        $url = rtrim((string) config('products.public_base_url'), '/')
            .'/'.rawurlencode((string) $product->profile->alias)
            .'/productos/'.rawurlencode($product->slug);
        $version = $product->updated_at?->getTimestamp();

        return $version ? "{$url}?v={$version}" : $url;
    }

    public function actionUrl(ProfileProduct $product): string
    {
        if ($product->destination_type === ProfileProductDestinationType::ExternalUrl) {
            return (string) $product->destination_url;
        }

        $phone = $this->internationalPhone($product->country_code, $product->phone_number);
        $message = rawurlencode($this->message($product));

        return match ($product->destination_type) {
            ProfileProductDestinationType::WhatsApp => "https://wa.me/{$phone}?text={$message}",
            ProfileProductDestinationType::Telegram => "https://t.me/+{$phone}?text={$message}",
            default => (string) $product->destination_url,
        };
    }

    public function message(ProfileProduct $product): string
    {
        $profile = $product->profile;
        $description = Str::limit(
            trim($product->description),
            max(1, (int) config('products.message_description_limit', 120)),
            '...'
        );
        $url = $this->publicUrl($product);

        if (($profile->locale ?: 'es') === 'en') {
            return trim("Hi, I'm interested in \"{$product->name}\".\n\n{$description}\n\n{$url}");
        }

        return trim("Hola, estoy interesado en \"{$product->name}\".\n\n{$description}\n\n{$url}");
    }

    public function internationalPhone(?string $countryCode, ?string $phoneNumber): string
    {
        return preg_replace('/\D+/', '', (string) $countryCode.(string) $phoneNumber) ?: '';
    }
}
