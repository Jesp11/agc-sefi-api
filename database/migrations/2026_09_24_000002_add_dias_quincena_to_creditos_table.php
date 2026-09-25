<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creditos', function (Blueprint $table) {
            // Días del mes en que vencen los pagos quincenales (p. ej. 15 y 30).
            $table->unsignedTinyInteger('dia_quincena_1')->nullable()->after('frecuencia_pago');
            $table->unsignedTinyInteger('dia_quincena_2')->nullable()->after('dia_quincena_1');
        });
    }

    public function down(): void
    {
        Schema::table('creditos', function (Blueprint $table) {
            $table->dropColumn(['dia_quincena_1', 'dia_quincena_2']);
        });
    }
};
