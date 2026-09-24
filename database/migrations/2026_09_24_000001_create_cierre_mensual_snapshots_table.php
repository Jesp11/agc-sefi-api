<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cierre_mensual_snapshots')) {
            return;
        }

        Schema::create('cierre_mensual_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('mes', 7)->unique();
            $table->decimal('cartera_total', 14, 2)->default(0);
            $table->decimal('mora_activa', 14, 2)->default(0);
            $table->decimal('mora_muerta', 14, 2)->default(0);
            $table->timestamp('capturado_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cierre_mensual_snapshots');
    }
};
