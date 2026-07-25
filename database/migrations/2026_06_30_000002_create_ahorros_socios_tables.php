<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ahorros_personal', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asesor_id')->unique();
            $table->decimal('saldo', 12, 2)->default(0);
            $table->timestamps();

            $table->foreign('asesor_id')->references('id')->on('asesores')->cascadeOnDelete();
        });

        Schema::create('ahorro_personal_movimientos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ahorro_personal_id');
            $table->enum('tipo', ['Ingreso', 'Retiro'])->default('Ingreso');
            $table->decimal('monto', 12, 2);
            $table->date('fecha');
            $table->text('notas')->nullable();
            $table->unsignedBigInteger('registrado_por')->nullable();
            $table->timestamps();

            $table->foreign('ahorro_personal_id')->references('id')->on('ahorros_personal')->cascadeOnDelete();
            $table->foreign('registrado_por')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('socios', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('codigo')->unique();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('ahorros_socio', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('socio_id')->unique();
            $table->decimal('saldo', 12, 2)->default(0);
            $table->timestamps();

            $table->foreign('socio_id')->references('id')->on('socios')->cascadeOnDelete();
        });

        Schema::create('ahorro_socio_movimientos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ahorro_socio_id');
            $table->enum('tipo', ['Ingreso', 'Retiro'])->default('Ingreso');
            $table->decimal('monto', 12, 2);
            $table->date('fecha');
            $table->text('notas')->nullable();
            $table->unsignedBigInteger('registrado_por')->nullable();
            $table->timestamps();

            $table->foreign('ahorro_socio_id')->references('id')->on('ahorros_socio')->cascadeOnDelete();
            $table->foreign('registrado_por')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ahorro_socio_movimientos');
        Schema::dropIfExists('ahorros_socio');
        Schema::dropIfExists('socios');
        Schema::dropIfExists('ahorro_personal_movimientos');
        Schema::dropIfExists('ahorros_personal');
    }
};
