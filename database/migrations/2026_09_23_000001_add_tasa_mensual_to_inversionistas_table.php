<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inversionistas', function (Blueprint $table) {
            $table->decimal('tasa_mensual', 5, 2)->default(0)->after('tasa_preferencial');
        });

        // Conserva las tasas que anteriormente estaban fijas en el reporte.
        $tasasExistentes = [
            'MARIA GUADALUPE DIAZ RODRIGUEZ' => 4.00,
            'JUANA SANCHEZ MORALES' => 12.00,
            'ISIDORA HERNANDEZ GARCIA 1' => 4.00,
            'ISIDORA HERNANDEZ GARCIA 2' => 4.00,
            'JOSSUE GIBRAN SOBREVILLA DIAZ 1' => 5.00,
            'JOSSUE GIBRAN SOBREVILLA DIAZ 2' => 2.00,
        ];

        foreach ($tasasExistentes as $nombre => $tasa) {
            DB::table('inversionistas')
                ->whereRaw('UPPER(nombre) = ?', [$nombre])
                ->update(['tasa_mensual' => $tasa]);
        }
    }

    public function down(): void
    {
        Schema::table('inversionistas', function (Blueprint $table) {
            $table->dropColumn('tasa_mensual');
        });
    }
};
