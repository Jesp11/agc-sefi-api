<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_caja', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->unsignedBigInteger('id_asesor')->nullable();
            $table->text('motivo');
            $table->enum('tipo', ['Ingreso', 'Egreso']);
            $table->decimal('monto', 14, 2);
            $table->decimal('saldo_resultante', 14, 2)->nullable();
            $table->string('categoria')->nullable();
            $table->string('cuenta')->nullable()->comment('Efectivo, Spin, Bancomer, etc.');
            $table->unsignedBigInteger('num_prog')->nullable();
            $table->unsignedBigInteger('pago_id')->nullable();
            $table->string('referencia')->nullable();
            $table->unsignedBigInteger('registrado_por')->nullable();
            $table->timestamps();

            $table->foreign('id_asesor')->references('id')->on('asesores')->nullOnDelete();
            $table->foreign('num_prog')->references('num_prog')->on('creditos')->nullOnDelete();
            $table->foreign('pago_id')->references('id')->on('pagos')->nullOnDelete();
            $table->foreign('registrado_por')->references('id')->on('users')->nullOnDelete();
            $table->index(['fecha', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_caja');
    }
};
