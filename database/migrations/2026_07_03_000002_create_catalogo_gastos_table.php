<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalogo_gastos', function (Blueprint $table) {
            $table->id();
            $table->string('concepto');
            $table->string('categoria')->nullable();
            $table->decimal('monto_sugerido', 12, 2)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::table('gastos_operativos', function (Blueprint $table) {
            $table->unsignedBigInteger('catalogo_gasto_id')->nullable()->after('categoria');
            $table->foreign('catalogo_gasto_id')->references('id')->on('catalogo_gastos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gastos_operativos', function (Blueprint $table) {
            $table->dropForeign(['catalogo_gasto_id']);
            $table->dropColumn('catalogo_gasto_id');
        });

        Schema::dropIfExists('catalogo_gastos');
    }
};
