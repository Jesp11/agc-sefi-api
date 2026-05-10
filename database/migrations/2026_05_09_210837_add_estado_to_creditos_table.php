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
        Schema::table('creditos', function (Blueprint $table) {
            $table->enum('estado', ['Activo', 'Finalizado', 'Cancelado'])->default('Activo')->after('dias_pago');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('creditos', function (Blueprint $table) {
            $table->dropColumn('estado');
        });
    }
};
