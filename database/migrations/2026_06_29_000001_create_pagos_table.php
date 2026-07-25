<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('num_prog');
            $table->decimal('monto', 12, 2);
            $table->date('fecha');
            $table->time('hora');
            $table->enum('metodo_pago', ['Efectivo', 'Transferencia', 'Otro'])->default('Efectivo');
            $table->enum('tipo', ['Abono', 'Multa'])->default('Abono');
            $table->text('notas')->nullable();
            $table->unsignedBigInteger('registrado_por')->nullable();
            $table->timestamps();

            $table->foreign('num_prog')->references('num_prog')->on('creditos')->onDelete('cascade');
            $table->foreign('registrado_por')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
};
