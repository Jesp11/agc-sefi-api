<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->date('fecha_nacimiento')->nullable();
            $table->string('rfc')->nullable();
            $table->string('curp')->nullable();
            $table->string('nss')->nullable();
            $table->string('banco')->nullable();
            $table->string('cuenta_bancaria')->nullable();
            $table->decimal('despensa', 12, 2)->default(0);
            $table->decimal('apoyo_transporte', 12, 2)->default(0);
            $table->decimal('bono_productividad', 12, 2)->default(0);
            $table->decimal('aportacion_socio', 12, 2)->default(0);
        });

        Schema::table('nomina_periodos', function (Blueprint $table) {
            $table->string('referencia')->nullable();
            $table->string('firma_director_administrativo')->nullable();
            $table->string('firma_director_operativo')->nullable();
        });

        Schema::table('nomina_detalle', function (Blueprint $table) {
            $table->decimal('pago_base', 12, 2)->default(0)->after('sueldo_bruto');
            $table->decimal('despensa', 12, 2)->default(0)->after('pago_base');
            $table->decimal('apoyo_transporte', 12, 2)->default(0)->after('despensa');
            $table->decimal('bono_productividad', 12, 2)->default(0)->after('apoyo_transporte');
            $table->decimal('aportacion_socio', 12, 2)->default(0)->after('retencion_ahorro');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nomina_detalle', function (Blueprint $table) {
            $table->dropColumn(['pago_base', 'despensa', 'apoyo_transporte', 'bono_productividad', 'aportacion_socio']);
        });

        Schema::table('nomina_periodos', function (Blueprint $table) {
            $table->dropColumn(['referencia', 'firma_director_administrativo', 'firma_director_operativo']);
        });

        Schema::table('empleados', function (Blueprint $table) {
            $table->dropColumn([
                'fecha_nacimiento', 'rfc', 'curp', 'nss', 'banco', 'cuenta_bancaria', 
                'despensa', 'apoyo_transporte', 'bono_productividad', 'aportacion_socio'
            ]);
        });
    }
};
