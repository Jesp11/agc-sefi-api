<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE documentos_cliente MODIFY COLUMN tipo ENUM('INE', 'INEReverso', 'ComprobanteDomicilio', 'Foto', 'SolicitudPrestamo', 'Otro') NOT NULL DEFAULT 'Otro'");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE documentos_cliente MODIFY COLUMN tipo ENUM('INE', 'ComprobanteDomicilio', 'Foto', 'Otro') NOT NULL DEFAULT 'Otro'");
    }
};
