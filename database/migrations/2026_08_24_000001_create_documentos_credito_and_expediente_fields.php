<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creditos', function (Blueprint $table) {
            $table->string('ubicacion_expediente')->nullable()->after('dias_mora_cache');
            $table->text('notas_expediente')->nullable()->after('ubicacion_expediente');
        });

        Schema::create('documentos_credito', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('num_prog');
            $table->enum('tipo', [
                'PagareFirmado',
                'CartaAdeudoFirmada',
                'TarjetaCobroFirmada',
                'ContratoFirmado',
                'ComprobanteDevolucion',
                'Otro',
            ])->default('Otro');
            $table->string('nombre_archivo');
            $table->string('ruta');
            $table->unsignedBigInteger('subido_por')->nullable();
            $table->timestamps();

            $table->foreign('num_prog')->references('num_prog')->on('creditos')->cascadeOnDelete();
            $table->foreign('subido_por')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_credito');

        Schema::table('creditos', function (Blueprint $table) {
            $table->dropColumn(['ubicacion_expediente', 'notas_expediente']);
        });
    }
};
