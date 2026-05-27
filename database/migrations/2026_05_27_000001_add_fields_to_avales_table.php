<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avales', function (Blueprint $table) {
            $table->string('tiempo_conocer')->nullable()->after('parentesco');
            $table->string('ocupacion_laboral')->nullable()->after('tiempo_conocer');
            $table->string('empresa')->nullable()->after('ocupacion_laboral');
        });
    }

    public function down(): void
    {
        Schema::table('avales', function (Blueprint $table) {
            $table->dropColumn(['tiempo_conocer', 'ocupacion_laboral', 'empresa']);
        });
    }
};
