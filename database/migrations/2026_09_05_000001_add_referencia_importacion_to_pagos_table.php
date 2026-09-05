<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            // Las referencias manuales quedan nulas; solamente la importación de
            // ruta las usa para que la misma cuota no pueda entrar dos veces.
            $table->string('referencia_importacion')->nullable()->unique()->after('notas');
        });
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropUnique(['referencia_importacion']);
            $table->dropColumn('referencia_importacion');
        });
    }
};
