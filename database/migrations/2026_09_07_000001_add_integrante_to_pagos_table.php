<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->string('id_cliente_integrante')->nullable()->after('num_prog');
            $table->index(['num_prog', 'id_cliente_integrante']);
        });
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropIndex(['num_prog', 'id_cliente_integrante']);
            $table->dropColumn('id_cliente_integrante');
        });
    }
};
