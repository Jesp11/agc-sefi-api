<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pago_grupal_asignaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pago_id');
            $table->string('id_cliente_integrante');
            $table->decimal('monto', 12, 2);
            $table->timestamps();
            $table->foreign('pago_id')->references('id')->on('pagos')->cascadeOnDelete();
            $table->unique(['pago_id', 'id_cliente_integrante']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pago_grupal_asignaciones');
    }
};
