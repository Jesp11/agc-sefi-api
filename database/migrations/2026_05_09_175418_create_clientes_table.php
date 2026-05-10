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
        Schema::create('clientes', function (Blueprint $table) {
            $table->string('id_cliente')->primary();
            $table->string('nombre_completo');
            $table->string('curp')->unique();
            $table->string('clave_elector');
            $table->string('telefono');
            $table->text('direccion');
            $table->string('entre_calles');
            $table->string('ocupacion');
            $table->text('direccion_trabajo');
            $table->string('telefono_trabajo');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
