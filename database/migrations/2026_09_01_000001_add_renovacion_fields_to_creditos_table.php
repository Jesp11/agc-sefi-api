<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creditos', function (Blueprint $table) {
            $table->date('fecha_programada_renovacion')->nullable()->after('notas_expediente');
            $table->string('renovacion_autorizada', 50)->default('Pendiente')->after('fecha_programada_renovacion');
            $table->string('renovacion_tasa', 50)->nullable()->after('renovacion_autorizada');
        });
    }

    public function down(): void
    {
        Schema::table('creditos', function (Blueprint $table) {
            $table->dropColumn([
                'fecha_programada_renovacion',
                'renovacion_autorizada',
                'renovacion_tasa',
            ]);
        });
    }
};
