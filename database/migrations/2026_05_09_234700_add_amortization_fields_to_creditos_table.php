<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('creditos', function (Blueprint $table) {
            $table->string('tasa_asignada')->nullable()->after('ciclo')->comment('Ej. TCIN21, TCGN10');
            $table->decimal('porcentaje_interes', 5, 2)->nullable()->after('interes')->comment('Porcentaje de interes aplicado ej. 21.00');
            $table->json('tabla_amortizacion')->nullable()->after('dias_pago');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('creditos', function (Blueprint $table) {
            $table->dropColumn(['tasa_asignada', 'porcentaje_interes', 'tabla_amortizacion']);
        });
    }
};
