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
            $table->unsignedBigInteger('movimiento_reintegro_id')->nullable()->after('movimiento_caja_id');
            $table->unsignedBigInteger('reintegrado_por')->nullable()->after('cancelado_gestor_at');
            $table->timestamp('reintegrado_at')->nullable()->after('reintegrado_por');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE confirmaciones_movimientos MODIFY estado ENUM('Pendiente','EntregadoGestor','Confirmado','PendienteReintegro','Reintegrado','Cancelado') NOT NULL DEFAULT 'Pendiente'");
        }
    }

    public function down(): void
    {
        Schema::table('confirmaciones_movimientos', function (Blueprint $table) {
            $table->dropColumn(['movimiento_reintegro_id', 'reintegrado_por', 'reintegrado_at']);
        });
    }
};
