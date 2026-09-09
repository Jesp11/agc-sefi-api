<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('tipo_comprobante_domicilio', 100)->nullable()->after('entre_calles');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE documentos_cliente MODIFY COLUMN tipo ENUM('INE','INEReverso','ComprobanteDomicilio','Foto','FotoUbicacion','SolicitudPrestamo','Otro') NOT NULL DEFAULT 'Otro'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE documentos_cliente MODIFY COLUMN tipo ENUM('INE','INEReverso','ComprobanteDomicilio','Foto','SolicitudPrestamo','Otro') NOT NULL DEFAULT 'Otro'");
        }

        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('tipo_comprobante_domicilio');
        });
    }
};
