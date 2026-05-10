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
        Schema::create('creditos', function (Blueprint $table) {
            $table->id('num_prog');
            $table->string('id_cliente');
            $table->unsignedBigInteger('id_asesor');
            $table->date('fecha_otorgacion');
            $table->integer('ciclo');
            $table->decimal('monto_otorgado', 10, 2);
            $table->decimal('interes', 10, 2);
            $table->decimal('total', 10, 2);
            $table->integer('plazos');
            $table->decimal('valor_ficha', 10, 2);
            $table->string('dias_pago');
            $table->timestamps();

            $table->foreign('id_cliente')->references('id_cliente')->on('clientes')->onDelete('cascade');
            $table->foreign('id_asesor')->references('id')->on('asesores')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('creditos');
    }
};
