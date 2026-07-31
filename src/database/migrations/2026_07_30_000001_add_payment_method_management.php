<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_sources', function (Blueprint $table): void {
            $table->text('provider_source_ciphertext')->nullable()->after('provider_source_id');
            $table->string('provider_source_hash', 64)->nullable()->after('provider_source_ciphertext');
            $table->string('card_brand', 50)->nullable()->after('type');
            $table->string('card_last_four', 4)->nullable()->after('card_brand');
            $table->unsignedSmallInteger('card_exp_month')->nullable()->after('card_last_four');
            $table->unsignedSmallInteger('card_exp_year')->nullable()->after('card_exp_month');
            $table->boolean('is_default')->default(false)->after('reusable');
            $table->timestamp('disabled_at')->nullable()->after('last_used_at');
            $table->timestamp('provider_synced_at')->nullable()->after('disabled_at');

            $table->unique(
                ['provider', 'provider_source_hash'],
                'payment_sources_provider_source_hash_unique'
            );
            $table->index(
                ['user_id', 'is_default', 'disabled_at'],
                'payment_sources_user_default_enabled_index'
            );
        });

        DB::table('payment_sources')
            ->whereNotNull('provider_source_id')
            ->orderBy('id')
            ->each(function (object $source): void {
                $providerSourceId = trim((string) $source->provider_source_id);

                if ($providerSourceId === '') {
                    return;
                }

                $metadata = $this->arrayValue($source->metadata);
                $card = $this->arrayValue(data_get($metadata, 'metadata.card'));
                $publicData = $this->arrayValue(data_get($metadata, 'public_data'));

                DB::table('payment_sources')
                    ->where('id', $source->id)
                    ->update([
                        'provider_source_id' => null,
                        'provider_source_ciphertext' => Crypt::encryptString($providerSourceId),
                        'provider_source_hash' => $this->sourceHash(
                            (string) $source->provider,
                            $providerSourceId
                        ),
                        'card_brand' => $this->firstString([
                            $card['brand'] ?? null,
                            $publicData['brand'] ?? null,
                            $publicData['type'] ?? null,
                        ]),
                        'card_last_four' => $this->lastFour([
                            $card['last_four'] ?? null,
                            $publicData['last_four'] ?? null,
                            $publicData['number'] ?? null,
                        ]),
                        'card_exp_month' => $this->integerOrNull(
                            $card['exp_month'] ?? $publicData['exp_month'] ?? null
                        ),
                        'card_exp_year' => $this->normalizedYear(
                            $card['exp_year'] ?? $publicData['exp_year'] ?? null
                        ),
                        'provider_synced_at' => now(),
                    ]);
            });

        DB::table('users')->orderBy('id')->each(function (object $user): void {
            $sourceId = DB::table('subscriptions')
                ->where('user_id', $user->id)
                ->where('active', true)
                ->whereNotNull('payment_source_id')
                ->orderByDesc('started_at')
                ->value('payment_source_id');

            $sourceId ??= DB::table('payment_orders')
                ->where('user_id', $user->id)
                ->where('status', 'approved')
                ->whereNotNull('payment_source_id')
                ->orderByDesc('paid_at')
                ->orderByDesc('id')
                ->value('payment_source_id');

            $sourceId ??= DB::table('payment_sources')
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->where('reusable', true)
                ->orderByDesc('last_used_at')
                ->orderByDesc('id')
                ->value('id');

            if ($sourceId !== null) {
                DB::table('payment_sources')
                    ->where('user_id', $user->id)
                    ->where('id', $sourceId)
                    ->update(['is_default' => true]);
            }
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX payment_sources_one_enabled_default_per_user
                ON payment_sources (user_id)
                WHERE is_default AND disabled_at IS NULL'
            );
        } elseif ($driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX payment_sources_one_enabled_default_per_user
                ON payment_sources (user_id)
                WHERE is_default = 1 AND disabled_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payment_sources_one_enabled_default_per_user');

        DB::table('payment_sources')
            ->whereNotNull('provider_source_ciphertext')
            ->orderBy('id')
            ->each(function (object $source): void {
                DB::table('payment_sources')
                    ->where('id', $source->id)
                    ->update([
                        'provider_source_id' => Crypt::decryptString(
                            (string) $source->provider_source_ciphertext
                        ),
                    ]);
            });

        Schema::table('payment_sources', function (Blueprint $table): void {
            $table->dropUnique('payment_sources_provider_source_hash_unique');
            $table->dropIndex('payment_sources_user_default_enabled_index');
            $table->dropColumn([
                'provider_source_ciphertext',
                'provider_source_hash',
                'card_brand',
                'card_last_four',
                'card_exp_month',
                'card_exp_year',
                'is_default',
                'disabled_at',
                'provider_synced_at',
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @param  array<mixed>  $values
     */
    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return mb_substr(trim((string) $value), 0, 50);
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $values
     */
    private function lastFour(array $values): ?string
    {
        $value = $this->firstString($values);

        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits === '' ? null : substr($digits, -4);
    }

    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function normalizedYear(mixed $value): ?int
    {
        $year = $this->integerOrNull($value);

        if ($year === null) {
            return null;
        }

        return $year < 100 ? 2000 + $year : $year;
    }

    private function sourceHash(string $provider, string $providerSourceId): string
    {
        return hash('sha256', strtolower(trim($provider)).':'.trim($providerSourceId));
    }
};
