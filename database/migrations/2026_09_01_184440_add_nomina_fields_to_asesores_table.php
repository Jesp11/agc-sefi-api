<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asesores', function (Blueprint $table) {
            $table->string('rfc')->nullable();
            $table->string('nss')->nullable();
            $table->string('banco')->nullable();
            $table->string('cuenta_bancaria')->nullable();
            $table->decimal('sueldo_base', 12, 2)->default(0);
            $table->decimal('despensa', 12, 2)->default(0);
            $table->decimal('apoyo_transporte', 12, 2)->default(0);
            $table->decimal('bono_productividad', 12, 2)->default(0);
            $table->decimal('aportacion_socio', 12, 2)->default(0);
            $table->boolean('activo')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('asesores', function (Blueprint $table) {
            $table->dropColumn([
                'rfc', 'nss', 'banco', 'cuenta_bancaria', 
                'sueldo_base', 'despensa', 'apoyo_transporte', 
                'bono_productividad', 'aportacion_socio', 'activo'
            ]);
        });
    }
};
