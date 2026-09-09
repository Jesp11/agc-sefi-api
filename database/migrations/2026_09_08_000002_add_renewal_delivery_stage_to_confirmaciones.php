<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('confirmaciones_movimientos', function (Blueprint $table) {
            $table->unsignedBigInteger('entregado_gestor_por')->nullable()->after('confirmado_at');
            $table->timestamp('entregado_gestor_at')->nullable()->after('entregado_gestor_por');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE confirmaciones_movimientos MODIFY estado ENUM('Pendiente','EntregadoGestor','Confirmado','Cancelado') NOT NULL DEFAULT 'Pendiente'");
        }
    }
    public function down(): void
    {
        Schema::table('confirmaciones_movimientos', function (Blueprint $table) {
            $table->dropColumn(['entregado_gestor_por', 'entregado_gestor_at']);
        });
    }
};
