<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('curp', 18)->unique()->nullable()->after('email');
            $table->date('cumpleanos')->nullable()->after('curp');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['curp']);
            $table->dropColumn(['curp', 'cumpleanos']);
        });
    }
};
