<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->enum('estatus', ['Activo', 'CerradoSinRenovacion', 'Inactivo'])->default('Activo')->after('fecha_nacimiento');
            $table->date('fecha_cierre')->nullable()->after('estatus');
            $table->boolean('es_socio_preferencial')->default(false)->after('fecha_cierre');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['estatus', 'fecha_cierre', 'es_socio_preferencial']);
        });
    }
};
