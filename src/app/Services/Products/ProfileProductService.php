<?php

namespace App\Services\Products;

use App\Classes\Subscriptions\SubscriptionPlanCapabilityService;
use App\Enums\ProfileProductDestinationType;
use App\Enums\ProfileProductStatus;
use App\Models\Profile;
use App\Models\ProfileProduct;
use App\Models\ProfileProductImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ProfileProductService
{
    public function __construct(
        private readonly ProfileProductImageService $images,
        private readonly SubscriptionPlanCapabilityService $capabilities,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(
        Profile $profile,
        User $user,
        array $attributes,
        ?UploadedFile $image = null,
        ?ProfileProductImport $import = null
    ): ProfileProduct {
        $attributes = $this->normalizeAttributes($attributes);
        $publicId = (string) Str::uuid();
        $storedImage = $image ? $this->storeImage($profile, $publicId, $image) : null;

        if (! $storedImage && empty($attributes['image_url'])) {
            throw new InvalidArgumentException('A product image is required.');
        }

        try {
            return DB::transaction(function () use ($profile, $user, $attributes, $storedImage, $publicId, $import): ProfileProduct {
                $lockedProfile = Profile::query()
                    ->whereKey($profile->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertCapacity($lockedProfile);
                $this->assertExternalIdAvailable($lockedProfile, $attributes['external_id']);
                $finalImageUrl = $storedImage['url'] ?? $attributes['image_url'];
                $attributes['image_url'] = $finalImageUrl;
                $attributes['fingerprint'] = $this->fingerprint(
                    $attributes['external_id'],
                    $attributes['name'],
                    $attributes
                );

                return ProfileProduct::query()->create([
                    ...$attributes,
                    'public_id' => $publicId,
                    'profile_id' => $lockedProfile->id,
                    'user_id' => $user->id,
                    'profile_product_import_id' => $import?->id,
                    'slug' => $this->uniqueSlug($lockedProfile, $attributes['name']),
                    'image_source' => $storedImage ? 'uploaded' : 'remote',
                    'image_url' => $finalImageUrl,
                    'storage_disk' => $storedImage['disk'] ?? null,
                    'storage_path' => $storedImage['path'] ?? null,
                    'social_storage_path' => $storedImage['social_path'] ?? null,
                    'social_image_mime_type' => $storedImage['social_mime_type'] ?? null,
                    'social_image_width' => $storedImage['social_width'] ?? null,
                    'social_image_height' => $storedImage['social_height'] ?? null,
                    'published_at' => $attributes['status'] === ProfileProductStatus::Published->value ? now() : null,
                ]);
            });
        } catch (\Throwable $e) {
            if ($storedImage) {
                Storage::disk($storedImage['disk'])->delete($storedImage['path']);
                Storage::disk($storedImage['disk'])->deleteDirectory(dirname($storedImage['path']));
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(ProfileProduct $product, array $attributes, ?UploadedFile $image = null): ProfileProduct
    {
        $attributes = $this->normalizeAttributes([
            ...$product->toArray(),
            ...$attributes,
        ]);
        $storedImage = $image ? $this->storeImage($product->profile, $product->public_id, $image) : null;
        $previousDisk = $product->storage_disk;
        $previousPath = $product->storage_path;
        $previousSocialPath = $product->social_storage_path;

        try {
            $product = DB::transaction(function () use ($product, $attributes, $storedImage): ProfileProduct {
                $this->assertExternalIdAvailable($product->profile, $attributes['external_id'], $product->id);
                $wasPublished = $product->status === ProfileProductStatus::Published;
                $useRemoteImage = ! $storedImage && $attributes['image_source'] === 'remote';
                $finalImageUrl = $storedImage['url']
                    ?? ($useRemoteImage ? $attributes['image_url'] : $product->image_url);
                $attributes['image_url'] = $finalImageUrl;
                $attributes['fingerprint'] = $this->fingerprint(
                    $attributes['external_id'],
                    $attributes['name'],
                    $attributes
                );

                $product->fill([
                    ...$attributes,
                    ...($storedImage ? [
                        'image_source' => 'uploaded',
                        'image_url' => $storedImage['url'],
                        'storage_disk' => $storedImage['disk'],
                        'storage_path' => $storedImage['path'],
                        'social_storage_path' => $storedImage['social_path'],
                        'social_image_mime_type' => $storedImage['social_mime_type'],
                        'social_image_width' => $storedImage['social_width'],
                        'social_image_height' => $storedImage['social_height'],
                    ] : ($useRemoteImage ? [
                        'image_source' => 'remote',
                        'image_url' => $attributes['image_url'],
                        'storage_disk' => null,
                        'storage_path' => null,
                        'social_storage_path' => null,
                        'social_image_mime_type' => null,
                        'social_image_width' => null,
                        'social_image_height' => null,
                    ] : [])),
                    'published_at' => $attributes['status'] === ProfileProductStatus::Published->value
                        ? ($wasPublished ? $product->published_at : now())
                        : null,
                ])->save();

                return $product->fresh(['profile']);
            });
        } catch (\Throwable $e) {
            if ($storedImage) {
                Storage::disk($storedImage['disk'])->delete(array_filter([
                    $storedImage['path'],
                    $storedImage['social_path'],
                ]));
            }

            throw $e;
        }

        if (
            filled($previousDisk)
            && filled($previousPath)
            && (
                ($storedImage && $previousPath !== $storedImage['path'])
                || (! $storedImage && $attributes['image_source'] === 'remote')
            )
        ) {
            if ($storedImage) {
                Storage::disk($previousDisk)->delete($previousPath);

                if (filled($previousSocialPath) && $previousSocialPath !== $storedImage['social_path']) {
                    Storage::disk($previousDisk)->delete($previousSocialPath);
                }
            } else {
                Storage::disk($previousDisk)->deleteDirectory(dirname($previousPath));
            }
        }

        return $product;
    }

    public function setEnabled(Profile $profile, bool $enabled): Profile
    {
        $profile->forceFill(['products_enabled' => $enabled])->save();

        return $profile->fresh();
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    public function bulkStatus(Profile $profile, array $ids, ProfileProductStatus $status): int
    {
        $attributes = [
            'status' => $status->value,
            'published_at' => $status === ProfileProductStatus::Published ? now() : null,
            'updated_at' => now(),
        ];

        return $profile->products()->whereKey($ids)->update($attributes);
    }

    /**
     * @param  array<int, int|string>  $ids
     * @param  array<string, mixed>  $destination
     */
    public function bulkDestination(Profile $profile, array $ids, array $destination): int
    {
        $destination = $this->normalizeDestination($destination);

        return $profile->products()->whereKey($ids)->update([
            ...$destination,
            'updated_at' => now(),
        ]);
    }

    public function delete(ProfileProduct $product): void
    {
        $disk = $product->storage_disk;
        $path = $product->storage_path;

        DB::transaction(fn () => $product->delete());

        if (filled($disk) && filled($path)) {
            Storage::disk($disk)->delete($path);
            Storage::disk($disk)->deleteDirectory(dirname($path));
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function fingerprint(?string $externalId, string $name, array $attributes = []): string
    {
        if (filled($externalId)) {
            return hash('sha256', 'external:'.$this->normalizeIdentityText((string) $externalId));
        }

        $identity = [
            'name' => $this->normalizeIdentityText($name),
            'description' => $this->normalizeIdentityText((string) ($attributes['description'] ?? '')),
            'image_url' => trim((string) ($attributes['image_url'] ?? '')),
            'destination_type' => (string) ($attributes['destination_type'] ?? ''),
            'destination_url' => trim((string) ($attributes['destination_url'] ?? '')),
            'country_code' => preg_replace('/\D+/', '', (string) ($attributes['country_code'] ?? '')) ?: '',
            'phone_number' => preg_replace('/\D+/', '', (string) ($attributes['phone_number'] ?? '')) ?: '',
        ];

        return hash('sha256', 'content:'.json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function normalizeAttributes(array $attributes): array
    {
        $destination = $this->normalizeDestination($attributes);
        $status = ProfileProductStatus::tryFrom((string) ($attributes['status'] ?? 'draft'))
            ?? ProfileProductStatus::Draft;
        $name = trim((string) ($attributes['name'] ?? ''));
        $description = trim((string) ($attributes['description'] ?? ''));
        $externalId = $this->nullableTrim($attributes['external_id'] ?? null);
        $imageUrl = $this->nullableTrim($attributes['image_url'] ?? null);
        $imageSource = ($attributes['image_source'] ?? null) === 'uploaded' ? 'uploaded' : 'remote';

        if ($name === '' || mb_strlen($name) > 180) {
            throw new InvalidArgumentException('The product name is required and may not exceed 180 characters.');
        }

        if ($description === '' || mb_strlen($description) > 2000) {
            throw new InvalidArgumentException('The product description is required and may not exceed 2000 characters.');
        }

        if ($imageSource === 'remote' && $imageUrl && ! $this->isSafeHttpUrl($imageUrl)) {
            throw new InvalidArgumentException('The product image URL must be a public HTTP or HTTPS URL.');
        }

        return [
            'external_id' => $externalId,
            'name' => $name,
            'description' => $description,
            'image_source' => $imageSource,
            'image_url' => $imageUrl,
            'status' => $status->value,
            'fingerprint' => $this->fingerprint($externalId, $name, [
                'description' => $description,
                'image_url' => $imageUrl,
                ...$destination,
            ]),
            ...$destination,
            'metadata' => is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : null,
        ];
    }

    public function maxProducts(Profile $profile): int
    {
        return $this->capabilities->productsPerProfile($profile);
    }

    private function assertCapacity(Profile $profile): void
    {
        $limit = $this->maxProducts($profile);

        if ($profile->products()->count() >= $limit) {
            throw new InvalidArgumentException("A profile can have up to {$limit} products.");
        }
    }

    private function assertExternalIdAvailable(Profile $profile, ?string $externalId, ?int $exceptId = null): void
    {
        if (! filled($externalId)) {
            return;
        }

        $exists = $profile->products()
            ->whereRaw('LOWER(external_id) = ?', [mb_strtolower(trim($externalId))])
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($exists) {
            throw new InvalidArgumentException('A product with the same external ID already exists.');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, null|string>
     */
    private function normalizeDestination(array $attributes): array
    {
        $type = ProfileProductDestinationType::tryFrom((string) ($attributes['destination_type'] ?? ''));

        if (! $type) {
            throw new InvalidArgumentException('A valid product destination is required.');
        }

        if ($type === ProfileProductDestinationType::ExternalUrl) {
            $url = trim((string) ($attributes['destination_url'] ?? ''));

            if (! $this->isSafeHttpUrl($url)) {
                throw new InvalidArgumentException('A valid HTTP or HTTPS product URL is required.');
            }

            return [
                'destination_type' => $type->value,
                'destination_url' => $url,
                'country_code' => null,
                'phone_number' => null,
            ];
        }

        $countryCode = preg_replace('/\D+/', '', (string) ($attributes['country_code'] ?? '')) ?: '';
        $phoneNumber = preg_replace('/\D+/', '', (string) ($attributes['phone_number'] ?? '')) ?: '';
        $phone = $countryCode.$phoneNumber;

        if ($countryCode === '' || $phoneNumber === '' || strlen($phone) < 7 || strlen($phone) > 15) {
            throw new InvalidArgumentException('A valid country code and phone number are required.');
        }

        return [
            'destination_type' => $type->value,
            'destination_url' => null,
            'country_code' => $countryCode,
            'phone_number' => $phoneNumber,
        ];
    }

    /**
     * @return array{
     *     disk: string,
     *     path: string,
     *     social_height: null|int,
     *     social_mime_type: null|string,
     *     social_path: null|string,
     *     social_width: null|int,
     *     url: string
     * }
     */
    private function storeImage(Profile $profile, string $publicId, UploadedFile $image): array
    {
        $mimeType = strtolower((string) $image->getMimeType());
        $allowed = ['image/gif', 'image/jpeg', 'image/png', 'image/webp'];

        if (! in_array($mimeType, $allowed, true)) {
            throw new InvalidArgumentException('Only JPG, PNG, WEBP, or GIF product images are supported.');
        }

        $maxBytes = max(1, (int) config('products.max_image_size_mb', 10)) * 1024 * 1024;

        if ((int) $image->getSize() > $maxBytes) {
            throw new InvalidArgumentException('The product image exceeds the configured size limit.');
        }

        $disk = (string) config('products.disk', 'profiles');
        $folder = trim((string) config('products.folder', 'products'), '/');
        $extension = strtolower($image->guessExtension() ?: $image->getClientOriginalExtension() ?: 'bin');
        $realPath = $image->getRealPath();
        $hash = is_string($realPath) ? hash_file('sha256', $realPath) : false;
        $hash = substr($hash ?: Str::random(32), 0, 16);
        $path = "{$folder}/{$profile->id}/{$publicId}/image-{$hash}.{$extension}";

        Storage::disk($disk)->putFileAs(
            dirname($path),
            $image,
            basename($path),
            ['visibility' => (string) config('products.visibility', 'public')]
        );
        $social = $this->images->storeSocialPreview(
            $image,
            $disk,
            dirname($path),
            (string) config('products.visibility', 'public')
        );

        return [
            'disk' => $disk,
            'path' => $path,
            'social_path' => $social['path'],
            'social_mime_type' => $social['mime_type'],
            'social_width' => $social['width'],
            'social_height' => $social['height'],
            'url' => Storage::disk($disk)->url($path),
        ];
    }

    private function uniqueSlug(Profile $profile, string $name): string
    {
        $base = Str::slug($name) ?: 'producto';
        $slug = $base;
        $suffix = 2;

        while ($profile->products()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || in_array(mb_strtolower($value), ['null', 'undefined'], true)) {
            return null;
        }

        return $value;
    }

    private function normalizeIdentityText(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    private function isSafeHttpUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
