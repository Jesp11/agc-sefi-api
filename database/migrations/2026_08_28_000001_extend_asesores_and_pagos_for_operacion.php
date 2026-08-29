<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asesores', function (Blueprint $table) {
            $table->string('rol_laboral')->nullable()->after('telefono');
        });

        Schema::table('pagos', function (Blueprint $table) {
            $table->decimal('ahorro_personal_monto', 12, 2)->default(0)->after('monto');
        });
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropColumn('ahorro_personal_monto');
        });

        Schema::table('asesores', function (Blueprint $table) {
            $table->dropColumn('rol_laboral');
        });
    }
};
