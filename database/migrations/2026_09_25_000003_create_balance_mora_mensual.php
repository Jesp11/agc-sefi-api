<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balance_mora_mensual', function (Blueprint $table) {
            $table->string('mes', 7)->primary();
            $table->decimal('mora_activa', 14, 2);
            $table->decimal('mora_muerta', 14, 2);
            $table->string('origen', 20);
            $table->timestamps();
        });

        // Importes autorizados del cuadro "Informe de mora del año 2026".
        $historico = [
            ['2026-01', 54245.00, 0.00],
            ['2026-02', 94761.00, 0.00],
            ['2026-03', 103703.00, 0.00],
            ['2026-04', 103011.00, 0.00],
            ['2026-05', 100831.00, 0.00],
            ['2026-06', 84930.00, 15901.00],
            ['2026-07', 66669.00, 43271.00],
            ['2026-08', 19797.00, 109940.00],
        ];

        DB::table('balance_mora_mensual')->insert(array_map(
            fn (array $row) => [
                'mes' => $row[0],
                'mora_activa' => $row[1],
                'mora_muerta' => $row[2],
                'origen' => 'historico',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            $historico
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_mora_mensual');
    }
};
