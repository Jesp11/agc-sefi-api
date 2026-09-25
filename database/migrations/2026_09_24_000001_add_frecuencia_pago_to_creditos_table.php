<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creditos', function (Blueprint $table) {
            // Periodicidad de los pagos: los créditos existentes son semanales.
            $table->string('frecuencia_pago', 20)->default('Semanal')->after('dias_pago');
        });
    }

    public function down(): void
    {
        Schema::table('creditos', function (Blueprint $table) {
            $table->dropColumn('frecuencia_pago');
        });
    }
};
