<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ciclos_historial', function (Blueprint $table) {
            $table->id();
            $table->string('id_cliente')->nullable();
            $table->unsignedBigInteger('id_grupo')->nullable();
            $table->integer('ciclo');
            $table->unsignedBigInteger('num_prog');
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->enum('resultado', ['Liquidado', 'CerradoSR', 'Refinanciado', 'Activo'])->default('Activo');
            $table->timestamps();

            $table->foreign('id_cliente')->references('id_cliente')->on('clientes')->nullOnDelete();
            $table->foreign('id_grupo')->references('id')->on('grupos')->nullOnDelete();
            $table->foreign('num_prog')->references('num_prog')->on('creditos')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ciclos_historial');
    }
};
