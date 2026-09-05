<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refinanciamientos', function (Blueprint $table) {
            $table->unique('num_prog_anterior', 'refinanciamientos_anterior_unique');
            $table->unique('num_prog_nuevo', 'refinanciamientos_nuevo_unique');
        });
    }

    public function down(): void
    {
        Schema::table('refinanciamientos', function (Blueprint $table) {
            $table->dropUnique('refinanciamientos_anterior_unique');
            $table->dropUnique('refinanciamientos_nuevo_unique');
        });
    }
};
