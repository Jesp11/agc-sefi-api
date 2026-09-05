<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creditos', function (Blueprint $table) {
            $table->decimal('abonos_historicos', 12, 2)->default(0)->after('saldo_pendiente')
                ->comment('Abonos reconocidos sin registro de pago ni movimiento de caja');
        });
    }

    public function down(): void
    {
        Schema::table('creditos', function (Blueprint $table) {
            $table->dropColumn('abonos_historicos');
        });
    }
};
