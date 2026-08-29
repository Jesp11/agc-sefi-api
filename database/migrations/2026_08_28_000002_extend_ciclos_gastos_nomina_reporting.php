<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ciclos_historial', function (Blueprint $table) {
            $table->json('snapshot')->nullable()->after('resultado');
            $table->date('fecha_consulta')->nullable()->after('fecha_fin');
        });

        Schema::table('gastos_operativos', function (Blueprint $table) {
            $table->string('cuenta')->nullable()->after('categoria');
        });

        Schema::table('empleados', function (Blueprint $table) {
            $table->json('percepciones_config')->nullable()->after('porcentaje_ahorro');
            $table->json('deducciones_config')->nullable()->after('percepciones_config');
        });

        Schema::table('nomina_detalle', function (Blueprint $table) {
            $table->decimal('total_percepciones', 12, 2)->default(0)->after('sueldo_bruto');
            $table->decimal('total_deducciones', 12, 2)->default(0)->after('retencion_ahorro');
            $table->json('detalle_ajustes')->nullable()->after('sueldo_neto');
        });
    }

    public function down(): void
    {
        Schema::table('nomina_detalle', function (Blueprint $table) {
            $table->dropColumn(['total_percepciones', 'total_deducciones', 'detalle_ajustes']);
        });

        Schema::table('empleados', function (Blueprint $table) {
            $table->dropColumn(['percepciones_config', 'deducciones_config']);
        });

        Schema::table('gastos_operativos', function (Blueprint $table) {
            $table->dropColumn('cuenta');
        });

        Schema::table('ciclos_historial', function (Blueprint $table) {
            $table->dropColumn(['snapshot', 'fecha_consulta']);
        });
    }
};
