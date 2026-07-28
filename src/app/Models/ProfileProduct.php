<?php

namespace App\Models;

use App\Enums\ProfileProductDestinationType;
use App\Enums\ProfileProductStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id',
        'profile_id',
        'user_id',
        'profile_product_import_id',
        'external_id',
        'slug',
        'name',
        'description',
        'image_source',
        'image_url',
        'storage_disk',
        'storage_path',
        'social_storage_path',
        'social_image_mime_type',
        'social_image_width',
        'social_image_height',
        'destination_type',
        'destination_url',
        'country_code',
        'phone_number',
        'status',
        'fingerprint',
        'published_at',
        'metadata',
    ];

    protected $casts = [
        'destination_type' => ProfileProductDestinationType::class,
        'status' => ProfileProductStatus::class,
        'published_at' => 'datetime',
        'metadata' => 'array',
        'social_image_width' => 'integer',
        'social_image_height' => 'integer',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(ProfileProductImport::class, 'profile_product_import_id');
    }
}
