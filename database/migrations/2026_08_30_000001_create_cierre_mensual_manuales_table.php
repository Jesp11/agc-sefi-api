<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cierre_mensual_manuales', function (Blueprint $table) {
            $table->id();
            $table->string('mes', 7)->unique();
            $table->decimal('aumento_cartera', 14, 2)->nullable();
            $table->decimal('cancelacion_credito_vehicular', 14, 2)->nullable();
            $table->decimal('pase_a_cartera_mora', 14, 2)->nullable();
            $table->decimal('productividad_mensual', 14, 2)->nullable();
            $table->unsignedBigInteger('registrado_por')->nullable();
            $table->timestamps();

            $table->foreign('registrado_por')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cierre_mensual_manuales');
    }
};
