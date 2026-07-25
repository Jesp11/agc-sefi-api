<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_sistema', function (Blueprint $table) {
            $table->id();
            $table->string('clave')->unique();
            $table->string('valor');
            $table->timestamps();
        });

        DB::table('configuracion_sistema')->insert([
            ['clave' => 'dias_cierre_sin_renovacion', 'valor' => '30', 'created_at' => now(), 'updated_at' => now()],
            ['clave' => 'comision_apertura_default', 'valor' => '100.00', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::table('grupos', function (Blueprint $table) {
            $table->boolean('es_socio_preferencial')->default(false)->after('id_asesor');
        });
    }

    public function down(): void
    {
        Schema::table('grupos', function (Blueprint $table) {
            $table->dropColumn('es_socio_preferencial');
        });
        Schema::dropIfExists('configuracion_sistema');
    }
};
