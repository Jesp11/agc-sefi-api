<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE confirmaciones_movimientos MODIFY estado ENUM('Pendiente','EntregadoGestor','Confirmado','PendienteReintegro','Reintegrado','Reprogramado','Cancelado') NOT NULL DEFAULT 'Pendiente'");
        }

        // Conserva trazabilidad clara para reintentos creados antes de este estado.
        DB::table('confirmaciones_movimientos')->where('estado', 'Reintegrado')->orderBy('id')->each(function ($movimiento) {
            $tieneReintento = DB::table('confirmaciones_movimientos')
                ->where('num_prog', $movimiento->num_prog)
                ->where('id', '>', $movimiento->id)
                ->whereIn('estado', ['Pendiente', 'EntregadoGestor'])
                ->exists();
            if ($tieneReintento) {
                DB::table('confirmaciones_movimientos')->where('id', $movimiento->id)->update(['estado' => 'Reprogramado']);
            }
        });
    }

    public function down(): void
    {
        DB::table('confirmaciones_movimientos')->where('estado', 'Reprogramado')->update(['estado' => 'Reintegrado']);
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE confirmaciones_movimientos MODIFY estado ENUM('Pendiente','EntregadoGestor','Confirmado','PendienteReintegro','Reintegrado','Cancelado') NOT NULL DEFAULT 'Pendiente'");
        }
    }
};
