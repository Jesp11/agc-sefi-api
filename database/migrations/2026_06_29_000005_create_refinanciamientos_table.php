<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refinanciamientos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('num_prog_anterior');
            $table->unsignedBigInteger('num_prog_nuevo');
            $table->decimal('saldo_anterior', 12, 2);
            $table->decimal('deduccion', 12, 2);
            $table->decimal('monto_neto', 12, 2);
            $table->decimal('intereses_arrastrados', 12, 2)->default(0);
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->foreign('num_prog_anterior')->references('num_prog')->on('creditos')->cascadeOnDelete();
            $table->foreign('num_prog_nuevo')->references('num_prog')->on('creditos')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refinanciamientos');
    }
};
