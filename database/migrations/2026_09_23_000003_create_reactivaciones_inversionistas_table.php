<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reactivaciones_inversionistas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inversionista_id')->constrained('inversionistas')->cascadeOnDelete();
            $table->date('fecha');
            $table->text('motivo');
            $table->unsignedBigInteger('realizado_por')->nullable();
            $table->timestamps();

            $table->index(['inversionista_id', 'fecha'], 'react_inv_inversionista_fecha_idx');
            $table->foreign('realizado_por', 'react_inv_usuario_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reactivaciones_inversionistas');
    }
};
