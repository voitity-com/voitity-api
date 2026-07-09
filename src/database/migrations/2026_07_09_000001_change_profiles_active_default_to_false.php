<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->setDefault(false);
    }

    public function down(): void
    {
        $this->setDefault(true);
    }

    private function setDefault(bool $active): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE profiles ALTER COLUMN active SET DEFAULT '.($active ? 'true' : 'false'));

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE profiles MODIFY active TINYINT(1) NOT NULL DEFAULT '.($active ? '1' : '0'));
        }
    }
};
