<?php

namespace App\Http\Responses\Products;

use App\Models\ProfileProductImport;
use App\Models\ProfileProductImportRow;
use App\Services\Products\ProfileProductImageService;

class ProfileProductImportResponse
{
    public function __construct(private readonly ProfileProductImport $import) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $this->import->loadMissing(['rows.duplicateProduct', 'rows.duplicateRow']);
        $images = app(ProfileProductImageService::class);

        return [
            'id' => $this->import->id,
            'original_filename' => $this->import->original_filename,
            'status' => $this->import->status->value,
            'total_rows' => $this->import->total_rows,
            'valid_rows' => $this->import->valid_rows,
            'invalid_rows' => $this->import->invalid_rows,
            'duplicate_rows' => $this->import->duplicate_rows,
            'summary' => $this->import->summary,
            'applied_at' => $this->import->applied_at?->toJSON(),
            'rows' => $this->import->rows
                ->map(fn (ProfileProductImportRow $row): array => [
                    'id' => $row->id,
                    'row_number' => $row->row_number,
                    'payload' => $row->payload,
                    'status' => $row->status->value,
                    'errors' => $row->errors,
                    'duplicate_product' => $row->duplicateProduct ? [
                        'id' => $row->duplicateProduct->id,
                        'name' => $row->duplicateProduct->name,
                        'image_url' => $images->imageUrl($row->duplicateProduct),
                        'status' => $row->duplicateProduct->status->value,
                    ] : null,
                    'duplicate_row_id' => $row->duplicate_row_id,
                    'duplicate_row_number' => $row->duplicateRow?->row_number,
                ])
                ->values()
                ->all(),
        ];
    }
}
