<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asesores', function (Blueprint $table) {
            $table->string('id_asesor')->unique()->nullable()->after('id');
            $table->string('curp', 18)->unique()->after('nombre_asesor');
            $table->date('cumpleanos')->after('curp');
        });
    }

    public function down(): void
    {
        Schema::table('asesores', function (Blueprint $table) {
            $table->dropUnique(['id_asesor']);
            $table->dropColumn(['id_asesor', 'curp', 'cumpleanos']);
        });
    }
};
