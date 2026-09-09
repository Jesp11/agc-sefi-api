<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('confirmaciones_movimientos', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->unsignedBigInteger('id_asesor')->nullable();
            $table->text('motivo');
            $table->decimal('monto', 14, 2);
            $table->string('categoria')->nullable();
            $table->string('cuenta')->nullable();
            $table->unsignedBigInteger('num_prog')->nullable();
            $table->string('referencia')->nullable()->unique();
            $table->enum('estado', ['Pendiente', 'EntregadoGestor', 'Confirmado', 'PendienteReintegro', 'Reintegrado', 'Reprogramado', 'Cancelado'])->default('Pendiente');
            $table->unsignedBigInteger('movimiento_caja_id')->nullable();
            $table->unsignedBigInteger('solicitado_por')->nullable();
            $table->unsignedBigInteger('confirmado_por')->nullable();
            $table->timestamp('confirmado_at')->nullable();
            $table->timestamps();

            $table->index(['estado', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('confirmaciones_movimientos');
    }
};
