<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_cliente', function (Blueprint $table) {
            $table->id();
            $table->string('id_cliente');
            $table->enum('tipo', ['INE', 'ComprobanteDomicilio', 'Foto', 'Otro'])->default('Otro');
            $table->string('nombre_archivo');
            $table->string('ruta');
            $table->unsignedBigInteger('subido_por')->nullable();
            $table->timestamps();

            $table->foreign('id_cliente')->references('id_cliente')->on('clientes')->cascadeOnDelete();
            $table->foreign('subido_por')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_cliente');
    }
};
