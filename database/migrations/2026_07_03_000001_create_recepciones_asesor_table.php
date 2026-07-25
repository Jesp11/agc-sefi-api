<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recepciones_asesor', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->unsignedBigInteger('id_asesor');
            $table->decimal('monto_esperado', 12, 2)->default(0);
            $table->decimal('monto_recibido', 12, 2);
            $table->string('notas', 500)->nullable();
            $table->unsignedBigInteger('registrado_por')->nullable();
            $table->timestamps();

            $table->unique(['fecha', 'id_asesor']);
            $table->foreign('id_asesor')->references('id')->on('asesores')->cascadeOnDelete();
            $table->foreign('registrado_por')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recepciones_asesor');
    }
};
