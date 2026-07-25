<?php

use App\Models\AhorroSocio;
use App\Models\Asesor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ahorros_socio')) {
            return;
        }

        if (Schema::hasColumn('ahorros_socio', 'socio_id')) {
            Schema::table('ahorros_socio', function (Blueprint $table) {
                $table->dropForeign(['socio_id']);
            });

            DB::table('ahorro_socio_movimientos')->delete();
            DB::table('ahorros_socio')->delete();

            Schema::table('ahorros_socio', function (Blueprint $table) {
                $table->dropColumn('socio_id');
            });

            Schema::dropIfExists('socios');
        }

        if (!Schema::hasColumn('ahorros_socio', 'asesor_id')) {
            Schema::table('ahorros_socio', function (Blueprint $table) {
                $table->unsignedBigInteger('asesor_id')->unique()->after('id');
                $table->foreign('asesor_id')->references('id')->on('asesores')->cascadeOnDelete();
            });
        }

        foreach (Asesor::all() as $asesor) {
            AhorroSocio::firstOrCreate(['asesor_id' => $asesor->id], ['saldo' => 0]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('ahorros_socio') || !Schema::hasColumn('ahorros_socio', 'asesor_id')) {
            return;
        }

        DB::table('ahorro_socio_movimientos')->delete();
        DB::table('ahorros_socio')->delete();

        Schema::table('ahorros_socio', function (Blueprint $table) {
            $table->dropForeign(['asesor_id']);
            $table->dropColumn('asesor_id');
        });

        Schema::create('socios', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('codigo')->unique();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::table('ahorros_socio', function (Blueprint $table) {
            $table->unsignedBigInteger('socio_id')->unique()->after('id');
            $table->foreign('socio_id')->references('id')->on('socios')->cascadeOnDelete();
        });
    }
};
