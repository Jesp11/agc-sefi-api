<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balance_general_cartera_mensual', function (Blueprint $table) {
            $table->string('mes', 7)->primary();
            $table->decimal('valor_bruto', 14, 2);
            $table->decimal('valor_neto', 14, 2);
            $table->string('origen', 20);
            $table->timestamps();
        });

        // Importes autorizados del cuadro "Crecimiento de cartera 2026".
        $historico = [
            ['2026-01', 1090913.73, 648913.73],
            ['2026-02', 1055387.83, 620357.83],
            ['2026-03', 1085991.71, 655991.71],
            ['2026-04', 1060470.91, 660470.91],
            ['2026-05', 1245953.71, 775953.71],
            ['2026-06', 1158805.71, 745090.71],
            ['2026-07', 1138098.71, 758126.71],
            ['2026-08', 877458.71, 506229.71],
        ];

        DB::table('balance_general_cartera_mensual')->insert(array_map(
            fn (array $row) => [
                'mes' => $row[0],
                'valor_bruto' => $row[1],
                'valor_neto' => $row[2],
                'origen' => 'historico',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            $historico
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_general_cartera_mensual');
    }
};
