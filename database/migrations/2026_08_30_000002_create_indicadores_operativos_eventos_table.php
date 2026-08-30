<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indicadores_operativos_eventos', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->string('tipo', 50);
            $table->decimal('monto', 14, 2)->default(0);
            $table->unsignedBigInteger('num_prog')->nullable();
            $table->unsignedBigInteger('num_prog_relacionado')->nullable();
            $table->string('origen', 50)->nullable();
            $table->text('descripcion')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedBigInteger('registrado_por')->nullable();
            $table->timestamps();

            $table->foreign('num_prog')->references('num_prog')->on('creditos')->nullOnDelete();
            $table->foreign('num_prog_relacionado')->references('num_prog')->on('creditos')->nullOnDelete();
            $table->foreign('registrado_por')->references('id')->on('users')->nullOnDelete();

            $table->index(['fecha', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicadores_operativos_eventos');
    }
};
