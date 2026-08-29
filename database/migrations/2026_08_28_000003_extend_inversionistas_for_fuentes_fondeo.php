<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inversionistas', function (Blueprint $table) {
            $table->string('tipo_entidad')->default('Persona Fisica')->after('nombre');
            $table->string('origen_fondeo')->nullable()->after('tipo_entidad');
        });
    }

    public function down(): void
    {
        Schema::table('inversionistas', function (Blueprint $table) {
            $table->dropColumn(['tipo_entidad', 'origen_fondeo']);
        });
    }
};
