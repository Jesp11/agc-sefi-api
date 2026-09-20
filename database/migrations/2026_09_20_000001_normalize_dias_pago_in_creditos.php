<?php

use App\Support\DiaPago;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('creditos')
            ->select(['num_prog', 'dias_pago'])
            ->orderBy('num_prog')
            ->chunkById(500, function ($creditos): void {
                foreach ($creditos as $credito) {
                    $normalizado = DiaPago::normalizar($credito->dias_pago);
                    if ($normalizado !== $credito->dias_pago) {
                        DB::table('creditos')
                            ->where('num_prog', $credito->num_prog)
                            ->update(['dias_pago' => $normalizado]);
                    }
                }
            }, 'num_prog');
    }

    public function down(): void
    {
        // La forma original de escritura no puede reconstruirse de manera confiable.
    }
};
