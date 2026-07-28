<?php

namespace App\Models;

use App\Enums\ProfileProductImportRowStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileProductImportRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_product_import_id',
        'profile_id',
        'row_number',
        'payload',
        'fingerprint',
        'status',
        'duplicate_product_id',
        'duplicate_row_id',
        'errors',
    ];

    protected $casts = [
        'payload' => 'array',
        'status' => ProfileProductImportRowStatus::class,
        'errors' => 'array',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(ProfileProductImport::class, 'profile_product_import_id');
    }

    public function duplicateProduct(): BelongsTo
    {
        return $this->belongsTo(ProfileProduct::class, 'duplicate_product_id');
    }

    public function duplicateRow(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_row_id');
    }
}
