<?php

namespace App\Services\Products;

use App\Enums\ProfileProductImportRowStatus;
use App\Enums\ProfileProductImportStatus;
use App\Models\Profile;
use App\Models\ProfileProduct;
use App\Models\ProfileProductImport;
use App\Models\ProfileProductImportRow;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SplFileObject;

class ProfileProductCsvImportService
{
    public function __construct(private readonly ProfileProductService $products) {}

    public function preview(Profile $profile, User $user, UploadedFile $file): ProfileProductImport
    {
        $path = $file->getRealPath();

        if (! $path) {
            throw new InvalidArgumentException('The CSV file could not be read.');
        }

        $delimiter = $this->detectDelimiter($path);
        $csv = new SplFileObject($path);
        $csv->setCsvControl($delimiter);
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $header = null;
        $parsedRows = [];
        $maxRows = max(1, (int) config('products.csv_max_rows', 500));

        foreach ($csv as $index => $values) {
            if (! is_array($values) || $values === [null]) {
                continue;
            }

            if ($header === null) {
                $header = $this->normalizeHeader($values);
                $this->assertRequiredHeaders($header);

                continue;
            }

            if (count($parsedRows) >= $maxRows) {
                throw new InvalidArgumentException("The CSV may contain up to {$maxRows} product rows.");
            }

            $parsedRows[] = [
                'row_number' => $index + 1,
                'payload' => $this->normalizeRow($header, $values),
            ];
        }

        if ($header === null || $parsedRows === []) {
            throw new InvalidArgumentException('The CSV does not contain product rows.');
        }

        return DB::transaction(function () use ($profile, $user, $file, $path, $parsedRows): ProfileProductImport {
            $import = ProfileProductImport::query()->create([
                'profile_id' => $profile->id,
                'user_id' => $user->id,
                'original_filename' => $file->getClientOriginalName(),
                'file_hash' => hash_file('sha256', $path),
                'status' => ProfileProductImportStatus::Previewed,
            ]);
            $seen = [];
            $counts = [
                ProfileProductImportRowStatus::Valid->value => 0,
                ProfileProductImportRowStatus::Invalid->value => 0,
                ProfileProductImportRowStatus::DuplicateExisting->value => 0,
                ProfileProductImportRowStatus::DuplicateFile->value => 0,
            ];

            foreach ($parsedRows as $parsed) {
                $analysis = $this->analyzeRow($profile, $parsed['payload'], $seen);
                $row = $import->rows()->create([
                    'profile_id' => $profile->id,
                    'row_number' => $parsed['row_number'],
                    'payload' => $analysis['payload'],
                    'fingerprint' => $analysis['fingerprint'],
                    'status' => $analysis['status'],
                    'duplicate_product_id' => $analysis['duplicate_product_id'],
                    'duplicate_row_id' => $analysis['duplicate_row_id'],
                    'errors' => $analysis['errors'],
                ]);
                $counts[$analysis['status']->value]++;

                if ($analysis['fingerprint'] && ! isset($seen[$analysis['fingerprint']])) {
                    $seen[$analysis['fingerprint']] = $row->id;
                }
            }

            $duplicateRows = $counts[ProfileProductImportRowStatus::DuplicateExisting->value]
                + $counts[ProfileProductImportRowStatus::DuplicateFile->value];
            $import->forceFill([
                'total_rows' => count($parsedRows),
                'valid_rows' => $counts[ProfileProductImportRowStatus::Valid->value],
                'invalid_rows' => $counts[ProfileProductImportRowStatus::Invalid->value],
                'duplicate_rows' => $duplicateRows,
                'summary' => [
                    'available_slots' => max(0, $this->products->maxProducts($profile) - $profile->products()->count()),
                    'current_products' => $profile->products()->count(),
                    'max_products' => $this->products->maxProducts($profile),
                ],
            ])->save();

            return $import->fresh(['rows.duplicateProduct', 'rows.duplicateRow']);
        });
    }

    /**
     * @param  array<int, array{id: int|string, action: string}>  $decisions
     * @return array<string, mixed>
     */
    public function apply(Profile $profile, User $user, ProfileProductImport $import, array $decisions): array
    {
        if ($import->status === ProfileProductImportStatus::Applied) {
            return $import->summary ?? [];
        }

        $decisionMap = collect($decisions)->keyBy(fn (array $item): int => (int) $item['id']);
        $rows = $import->rows()->whereKey($decisionMap->keys())->get()->keyBy('id');
        $createRows = [];
        $replaceRows = [];
        $selectedFingerprints = [];

        foreach ($decisionMap as $rowId => $decision) {
            /** @var ProfileProductImportRow|null $row */
            $row = $rows->get((int) $rowId);
            $action = (string) ($decision['action'] ?? 'skip');

            if (! $row || $row->profile_product_import_id !== $import->id || $action === 'skip') {
                continue;
            }

            if ($row->status === ProfileProductImportRowStatus::Invalid) {
                throw new InvalidArgumentException("CSV row {$row->row_number} is invalid and cannot be imported.");
            }

            if (! $row->fingerprint || isset($selectedFingerprints[$row->fingerprint])) {
                throw new InvalidArgumentException('Only one CSV row may be selected for each duplicate product.');
            }

            $selectedFingerprints[$row->fingerprint] = true;

            if ($action === 'replace') {
                $replaceRows[] = $row;
            } elseif ($action === 'import') {
                $createRows[] = $row;
            } else {
                throw new InvalidArgumentException('Each CSV decision must be import, replace, or skip.');
            }
        }

        return DB::transaction(function () use ($profile, $user, $import, $createRows, $replaceRows): array {
            $lockedProfile = Profile::query()
                ->whereKey($profile->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $lockedImport = ProfileProductImport::query()
                ->whereKey($import->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedImport->status === ProfileProductImportStatus::Applied) {
                return $lockedImport->summary ?? [];
            }

            $existingProducts = $lockedProfile->products()->lockForUpdate()->get();
            $replacements = [];
            $creations = [];

            foreach ($replaceRows as $row) {
                $product = $existingProducts->firstWhere('id', $row->duplicate_product_id);

                if (
                    ! $product instanceof ProfileProduct
                    || ! $this->matchesIdentity($product, $row->payload, $row->fingerprint)
                ) {
                    throw new InvalidArgumentException("CSV row {$row->row_number} no longer matches an existing product.");
                }

                $replacements[] = [$product, $row];
            }

            foreach ($createRows as $row) {
                if ($this->findDuplicate($existingProducts, $row->payload, $row->fingerprint)) {
                    throw new InvalidArgumentException("CSV row {$row->row_number} now duplicates an existing product. Refresh the preview.");
                }

                $creations[] = $row;
            }

            $availableSlots = max(0, $this->products->maxProducts($profile) - $existingProducts->count());

            if (count($creations) > $availableSlots) {
                throw new InvalidArgumentException(
                    "Only {$availableSlots} additional products can be imported. Select which products to keep."
                );
            }

            foreach ($replacements as [$product, $row]) {
                $this->products->update($product, [
                    ...$row->payload,
                    'image_url' => $row->payload['image_url'],
                    'metadata' => [
                        ...($product->metadata ?? []),
                        'last_import_id' => $lockedImport->id,
                        'last_import_row' => $row->row_number,
                    ],
                ]);
            }

            foreach ($creations as $row) {
                $this->products->create(
                    $lockedProfile,
                    $user,
                    [
                        ...$row->payload,
                        'metadata' => [
                            'import_id' => $lockedImport->id,
                            'import_row' => $row->row_number,
                        ],
                    ],
                    import: $lockedImport
                );
            }

            $summary = [
                'created' => count($creations),
                'replaced' => count($replacements),
                'skipped' => max(0, $lockedImport->total_rows - count($creations) - count($replacements)),
                'product_count' => $lockedProfile->products()->count(),
                'max_products' => $this->products->maxProducts($profile),
            ];
            $lockedImport->forceFill([
                'status' => ProfileProductImportStatus::Applied,
                'summary' => $summary,
                'applied_at' => now(),
            ])->save();

            return $summary;
        });
    }

    /**
     * @param  array<int, string>  $header
     * @param  array<int, mixed>  $values
     * @return array<string, mixed>
     */
    private function normalizeRow(array $header, array $values): array
    {
        $row = [];

        foreach ($header as $index => $key) {
            $row[$key] = $this->nullableValue($values[$index] ?? null);
        }

        $destinationType = mb_strtolower((string) ($row['destination_type'] ?? 'external_url'));
        $destinationType = match ($destinationType) {
            'link', 'url', 'external', 'external_url' => 'external_url',
            'whatsapp', 'wa' => 'whatsapp',
            'telegram', 'tg' => 'telegram',
            default => $destinationType,
        };
        $status = mb_strtolower((string) ($row['status'] ?? 'draft'));

        if (
            in_array($destinationType, ['whatsapp', 'telegram'], true)
            && (! filled($row['country_code'] ?? null) || ! filled($row['phone_number'] ?? null))
        ) {
            $destinationType = 'external_url';
        }

        return [
            'external_id' => $row['external_id'] ?? null,
            'name' => $row['name'] ?? '',
            'description' => $row['description'] ?? '',
            'image_url' => $row['image_url'] ?? '',
            'destination_type' => $destinationType,
            'destination_url' => $row['destination_url'] ?? null,
            'country_code' => $row['country_code'] ?? null,
            'phone_number' => $row['phone_number'] ?? null,
            'status' => in_array($status, ['public', 'published'], true) ? 'published' : 'draft',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>  $seen
     * @return array<string, mixed>
     */
    private function analyzeRow(Profile $profile, array $payload, array $seen): array
    {
        $validator = Validator::make($payload, [
            'external_id' => ['nullable', 'string', 'max:191'],
            'name' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:2000'],
            'image_url' => ['required', 'url:http,https', 'max:2048'],
            'destination_type' => ['required', 'in:external_url,whatsapp,telegram'],
            'destination_url' => ['required', 'url:http,https', 'max:2048'],
            'country_code' => ['nullable', 'required_unless:destination_type,external_url', 'regex:/^\+?\d{1,4}$/'],
            'phone_number' => ['nullable', 'required_unless:destination_type,external_url', 'regex:/^[\d\s().-]{6,24}$/'],
            'status' => ['required', 'in:draft,published'],
        ]);

        if ($validator->fails()) {
            return [
                'payload' => $payload,
                'fingerprint' => null,
                'status' => ProfileProductImportRowStatus::Invalid,
                'duplicate_product_id' => null,
                'duplicate_row_id' => null,
                'errors' => $validator->errors()->toArray(),
            ];
        }

        try {
            $payload = $this->products->normalizeAttributes($payload);
        } catch (InvalidArgumentException $e) {
            return [
                'payload' => $payload,
                'fingerprint' => null,
                'status' => ProfileProductImportRowStatus::Invalid,
                'duplicate_product_id' => null,
                'duplicate_row_id' => null,
                'errors' => ['row' => [$e->getMessage()]],
            ];
        }

        $fingerprint = $payload['fingerprint'];
        $existing = $this->findDuplicate($profile->products()->get(), $payload, $fingerprint);

        if ($existing) {
            return [
                'payload' => $payload,
                'fingerprint' => $fingerprint,
                'status' => ProfileProductImportRowStatus::DuplicateExisting,
                'duplicate_product_id' => $existing->id,
                'duplicate_row_id' => null,
                'errors' => null,
            ];
        }

        if (isset($seen[$fingerprint])) {
            return [
                'payload' => $payload,
                'fingerprint' => $fingerprint,
                'status' => ProfileProductImportRowStatus::DuplicateFile,
                'duplicate_product_id' => null,
                'duplicate_row_id' => $seen[$fingerprint],
                'errors' => null,
            ];
        }

        return [
            'payload' => $payload,
            'fingerprint' => $fingerprint,
            'status' => ProfileProductImportRowStatus::Valid,
            'duplicate_product_id' => null,
            'duplicate_row_id' => null,
            'errors' => null,
        ];
    }

    private function detectDelimiter(string $path): string
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $line = is_array($lines) ? (string) ($lines[0] ?? '') : '';

        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function normalizeHeader(array $values): array
    {
        return array_map(function ($value): string {
            $value = preg_replace('/^\xEF\xBB\xBF/', '', trim((string) $value)) ?: '';
            $key = Str::snake(mb_strtolower(Str::ascii($value)));

            return match ($key) {
                'nombre' => 'name',
                'descripcion' => 'description',
                'image', 'imagen' => 'image_url',
                'link', 'url' => 'destination_url',
                default => $key,
            };
        }, $values);
    }

    /**
     * @param  array<int, string>  $header
     */
    private function assertRequiredHeaders(array $header): void
    {
        $required = ['name', 'description', 'image_url', 'destination_url'];
        $missing = array_values(array_diff($required, $header));

        if ($missing !== []) {
            throw new InvalidArgumentException('The CSV is missing required columns: '.implode(', ', $missing).'.');
        }

        if (count(array_unique($header)) !== count($header)) {
            throw new InvalidArgumentException('The CSV contains duplicate columns after applying supported aliases.');
        }
    }

    private function nullableValue(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || in_array(mb_strtolower($value), ['null', 'undefined'], true)) {
            return null;
        }

        return $value;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ProfileProduct>  $products
     * @param  array<string, mixed>  $payload
     */
    private function findDuplicate(
        \Illuminate\Support\Collection $products,
        array $payload,
        ?string $fingerprint
    ): ?ProfileProduct {
        $externalId = $payload['external_id'] ?? null;

        return $products->first(function (ProfileProduct $product) use ($externalId, $fingerprint): bool {
            if (filled($externalId)) {
                return filled($product->external_id)
                    && mb_strtolower(trim((string) $product->external_id)) === mb_strtolower(trim((string) $externalId));
            }

            return filled($fingerprint) && hash_equals((string) $product->fingerprint, (string) $fingerprint);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function matchesIdentity(ProfileProduct $product, array $payload, ?string $fingerprint): bool
    {
        return $this->findDuplicate(collect([$product]), $payload, $fingerprint) instanceof ProfileProduct;
    }
}
