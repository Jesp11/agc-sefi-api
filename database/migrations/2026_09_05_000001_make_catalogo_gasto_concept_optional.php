<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalogo_gastos', function (Blueprint $table) {
            $table->string('concepto')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Las categorías creadas bajo este esquema no tienen concepto, por lo
        // que volver a exigirlo podría perder información al hacer rollback.
    }
};
