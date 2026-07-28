<?php

namespace App\Http\Controllers;

use App\Enums\ProfileProductDestinationType;
use App\Enums\ProfileProductStatus;
use App\Enums\ProfileStatus;
use App\Models\Profile;
use App\Services\Products\ProfileProductImageService;
use App\Services\Products\ProfileProductLinkService;
use Illuminate\Contracts\View\View;

class PublicProductController extends Controller
{
    public function show(
        string $alias,
        string $slug,
        ProfileProductImageService $images,
        ProfileProductLinkService $links
    ): View {
        $profile = Profile::query()
            ->where('alias', $alias)
            ->where('active', true)
            ->where('status', ProfileStatus::Published->value)
            ->firstOrFail();
        $product = $profile->products()
            ->where('slug', $slug)
            ->where('status', ProfileProductStatus::Published->value)
            ->firstOrFail();
        $product->setRelation('profile', $profile);
        $locale = ($profile->locale ?: 'es') === 'en' ? 'en' : 'es';
        $openGraphImage = $images->openGraphImage($product);
        $actionLabel = match ($product->destination_type) {
            ProfileProductDestinationType::WhatsApp => $locale === 'en'
                ? 'Contact on WhatsApp'
                : 'Contactar por WhatsApp',
            ProfileProductDestinationType::Telegram => $locale === 'en'
                ? 'Contact on Telegram'
                : 'Contactar por Telegram',
            default => $locale === 'en' ? 'View product' : 'Ver producto',
        };

        return view('products.show', [
            'profile' => $profile,
            'product' => $product,
            'imageUrl' => $images->imageUrl($product),
            'openGraphImage' => $openGraphImage,
            'publicUrl' => $links->publicUrl($product),
            'actionUrl' => $links->actionUrl($product),
            'actionLabel' => $actionLabel,
            'locale' => $locale,
        ]);
    }
}
