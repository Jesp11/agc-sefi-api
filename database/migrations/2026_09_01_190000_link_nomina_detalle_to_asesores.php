<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nomina_detalle', function (Blueprint $table) {
            $table->dropForeign(['empleado_id']);
            $table->unsignedBigInteger('empleado_id')->nullable()->change();
            $table->foreign('empleado_id')->references('id')->on('empleados')->nullOnDelete();
            $table->unsignedBigInteger('asesor_id')->nullable()->after('empleado_id');
            $table->foreign('asesor_id')->references('id')->on('asesores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('nomina_detalle', function (Blueprint $table) {
            $table->dropForeign(['asesor_id']);
            $table->dropColumn('asesor_id');
            $table->dropForeign(['empleado_id']);
            $table->unsignedBigInteger('empleado_id')->nullable(false)->change();
            $table->foreign('empleado_id')->references('id')->on('empleados')->cascadeOnDelete();
        });
    }
};
