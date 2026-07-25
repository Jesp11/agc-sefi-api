<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inversionistas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('contacto')->nullable();
            $table->string('telefono')->nullable();
            $table->string('email')->nullable();
            $table->boolean('tasa_preferencial')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('aportaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inversionista_id');
            $table->decimal('monto', 14, 2);
            $table->date('fecha');
            $table->enum('tipo', ['Aportacion', 'Retiro'])->default('Aportacion');
            $table->text('notas')->nullable();
            $table->unsignedBigInteger('registrado_por')->nullable();
            $table->timestamps();

            $table->foreign('inversionista_id')->references('id')->on('inversionistas')->cascadeOnDelete();
            $table->foreign('registrado_por')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('movimientos_capital', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo', ['Aportacion', 'Retiro', 'Colocacion', 'Gasto', 'Nomina', 'Otro']);
            $table->decimal('monto', 14, 2);
            $table->string('referencia')->nullable();
            $table->date('fecha');
            $table->text('descripcion')->nullable();
            $table->unsignedBigInteger('registrado_por')->nullable();
            $table->timestamps();

            $table->foreign('registrado_por')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('gastos_operativos', function (Blueprint $table) {
            $table->id();
            $table->string('concepto');
            $table->decimal('monto', 12, 2);
            $table->date('fecha');
            $table->string('categoria')->nullable();
            $table->unsignedBigInteger('registrado_por')->nullable();
            $table->timestamps();

            $table->foreign('registrado_por')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('empleados', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('puesto')->nullable();
            $table->decimal('sueldo_base', 12, 2);
            $table->decimal('porcentaje_ahorro', 5, 2)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('nomina_periodos', function (Blueprint $table) {
            $table->id();
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->decimal('total_dispersado', 14, 2)->default(0);
            $table->unsignedBigInteger('registrado_por')->nullable();
            $table->timestamps();

            $table->foreign('registrado_por')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('nomina_detalle', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('periodo_id');
            $table->unsignedBigInteger('empleado_id');
            $table->decimal('sueldo_bruto', 12, 2);
            $table->decimal('retencion_ahorro', 12, 2)->default(0);
            $table->decimal('sueldo_neto', 12, 2);
            $table->timestamps();

            $table->foreign('periodo_id')->references('id')->on('nomina_periodos')->cascadeOnDelete();
            $table->foreign('empleado_id')->references('id')->on('empleados')->cascadeOnDelete();
        });

        Schema::create('ahorros_empleado', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empleado_id')->unique();
            $table->decimal('saldo', 12, 2)->default(0);
            $table->timestamps();

            $table->foreign('empleado_id')->references('id')->on('empleados')->cascadeOnDelete();
        });

        Schema::create('ahorro_movimientos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ahorro_id');
            $table->enum('tipo', ['Deduccion', 'Retiro'])->default('Deduccion');
            $table->decimal('monto', 12, 2);
            $table->date('fecha');
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->foreign('ahorro_id')->references('id')->on('ahorros_empleado')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ahorro_movimientos');
        Schema::dropIfExists('ahorros_empleado');
        Schema::dropIfExists('nomina_detalle');
        Schema::dropIfExists('nomina_periodos');
        Schema::dropIfExists('empleados');
        Schema::dropIfExists('gastos_operativos');
        Schema::dropIfExists('movimientos_capital');
        Schema::dropIfExists('aportaciones');
        Schema::dropIfExists('inversionistas');
    }
};
