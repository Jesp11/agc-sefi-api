<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE aportaciones MODIFY tipo ENUM('Aportacion', 'Retiro', 'Rendimiento') NOT NULL DEFAULT 'Aportacion'");
    }

    public function down(): void
    {
        if (DB::table('aportaciones')->where('tipo', 'Rendimiento')->exists()) {
            throw new \RuntimeException('No se puede revertir esta migración mientras existan pagos de rendimiento.');
        }

        DB::statement("ALTER TABLE aportaciones MODIFY tipo ENUM('Aportacion', 'Retiro') NOT NULL DEFAULT 'Aportacion'");
    }
};
