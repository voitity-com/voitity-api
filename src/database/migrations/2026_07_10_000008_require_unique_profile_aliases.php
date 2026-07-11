<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->backfillMissingAliases();
        $this->deduplicateActiveAliases();
        $this->createUniqueIndex();
        $this->enforceNotNullWhenSupported();
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS profiles_alias_unique');
        } elseif (Schema::hasTable('profiles')) {
            Schema::table('profiles', function ($table): void {
                $table->dropUnique('profiles_alias_unique');
            });
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE profiles ALTER COLUMN alias DROP NOT NULL');
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE profiles MODIFY alias VARCHAR(100) NULL');
        }
    }

    private function backfillMissingAliases(): void
    {
        DB::table('profiles')
            ->where(function ($query): void {
                $query->whereNull('alias')
                    ->orWhere('alias', '');
            })
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(100, function ($profiles): void {
                foreach ($profiles as $profile) {
                    DB::table('profiles')
                        ->where('id', $profile->id)
                        ->update(['alias' => "profile-{$profile->id}"]);
                }
            });
    }

    private function deduplicateActiveAliases(): void
    {
        $aliases = DB::table('profiles')
            ->select('alias')
            ->whereNull('deleted_at')
            ->whereNotNull('alias')
            ->groupBy('alias')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('alias');

        foreach ($aliases as $alias) {
            $profileIds = DB::table('profiles')
                ->whereNull('deleted_at')
                ->where('alias', $alias)
                ->orderBy('id')
                ->pluck('id')
                ->values();

            foreach ($profileIds->slice(1) as $profileId) {
                DB::table('profiles')
                    ->where('id', $profileId)
                    ->update(['alias' => $this->uniqueAliasCandidate((string) $alias, (int) $profileId)]);
            }
        }
    }

    private function uniqueAliasCandidate(string $alias, int $profileId): string
    {
        $attempt = 0;

        do {
            $suffix = $attempt === 0 ? "-{$profileId}" : "-{$profileId}-{$attempt}";
            $candidate = Str::limit($alias, 100 - strlen($suffix), '').$suffix;
            $attempt++;
        } while (
            DB::table('profiles')
                ->whereNull('deleted_at')
                ->where('id', '<>', $profileId)
                ->where('alias', $candidate)
                ->exists()
        );

        return $candidate;
    }

    private function createUniqueIndex(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS profiles_alias_unique
                ON profiles (alias)
                WHERE deleted_at IS NULL'
            );

            return;
        }

        Schema::table('profiles', function ($table): void {
            $table->unique('alias', 'profiles_alias_unique');
        });
    }

    private function enforceNotNullWhenSupported(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE profiles ALTER COLUMN alias SET NOT NULL');
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE profiles MODIFY alias VARCHAR(100) NOT NULL');
        }
    }
};
