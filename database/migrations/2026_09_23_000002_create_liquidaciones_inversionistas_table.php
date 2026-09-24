<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liquidaciones_inversionistas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inversionista_id')->constrained('inversionistas')->cascadeOnDelete();
            $table->decimal('capital', 14, 2);
            $table->decimal('rendimiento_final', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->date('fecha');
            $table->string('cuenta', 50);
            $table->text('notas')->nullable();
            $table->string('estado', 20)->default('Pendiente');
            $table->unsignedBigInteger('confirmacion_movimiento_id')->nullable();
            $table->unsignedBigInteger('aportacion_retiro_id')->nullable();
            $table->unsignedBigInteger('aportacion_rendimiento_id')->nullable();
            $table->unsignedBigInteger('movimiento_capital_retiro_id')->nullable();
            $table->unsignedBigInteger('movimiento_capital_rendimiento_id')->nullable();
            $table->unsignedBigInteger('solicitado_por')->nullable();
            $table->unsignedBigInteger('confirmado_por')->nullable();
            $table->timestamp('confirmado_at')->nullable();
            $table->timestamps();

            $table->index(['inversionista_id', 'estado']);
            $table->index(['estado', 'fecha']);
            $table->unique('confirmacion_movimiento_id', 'liq_inv_confirmacion_uq');
            $table->foreign('confirmacion_movimiento_id', 'liq_inv_confirmacion_fk')->references('id')->on('confirmaciones_movimientos')->nullOnDelete();
            $table->foreign('aportacion_retiro_id', 'liq_inv_ap_ret_fk')->references('id')->on('aportaciones')->nullOnDelete();
            $table->foreign('aportacion_rendimiento_id', 'liq_inv_ap_rend_fk')->references('id')->on('aportaciones')->nullOnDelete();
            $table->foreign('movimiento_capital_retiro_id', 'liq_inv_mc_ret_fk')->references('id')->on('movimientos_capital')->nullOnDelete();
            $table->foreign('movimiento_capital_rendimiento_id', 'liq_inv_mc_rend_fk')->references('id')->on('movimientos_capital')->nullOnDelete();
            $table->foreign('solicitado_por', 'liq_inv_solicitado_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('confirmado_por', 'liq_inv_confirmado_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liquidaciones_inversionistas');
    }
};
