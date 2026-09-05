<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refinanciamientos', function (Blueprint $table) {
            $table->date('fecha_efectiva')->nullable()->after('intereses_arrastrados')->index();
        });

        // Los vínculos ya existentes usan como referencia histórica la fecha de
        // otorgación del crédito nuevo. Los registros sin vínculo se dejan nulos
        // para que se completen mediante captura o importación explícita.
        DB::table('refinanciamientos')
            ->join('creditos as nuevo', 'nuevo.num_prog', '=', 'refinanciamientos.num_prog_nuevo')
            ->whereNull('refinanciamientos.fecha_efectiva')
            ->update(['refinanciamientos.fecha_efectiva' => DB::raw('nuevo.fecha_otorgacion')]);
    }

    public function down(): void
    {
        Schema::table('refinanciamientos', function (Blueprint $table) {
            $table->dropIndex(['fecha_efectiva']);
            $table->dropColumn('fecha_efectiva');
        });
    }
};
