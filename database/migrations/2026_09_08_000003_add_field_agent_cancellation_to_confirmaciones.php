<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('confirmaciones_movimientos', function (Blueprint $table) {
            $table->unsignedBigInteger('cancelado_gestor_por')->nullable()->after('entregado_gestor_at');
            $table->timestamp('cancelado_gestor_at')->nullable()->after('cancelado_gestor_por');
        });
    }

    public function down(): void
    {
        Schema::table('confirmaciones_movimientos', function (Blueprint $table) {
            $table->dropColumn(['cancelado_gestor_por', 'cancelado_gestor_at']);
        });
    }
};
