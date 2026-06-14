<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asesores', function (Blueprint $table) {
            $table->string('telefono', 20)->nullable()->after('cumpleanos');
            $table->string('ine_path')->nullable()->after('telefono');
        });
    }

    public function down(): void
    {
        Schema::table('asesores', function (Blueprint $table) {
            $table->dropColumn(['telefono', 'ine_path']);
        });
    }
};
