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

        if (Schema::hasColumn('ahorros_socio', 'asesor_id')) {
            if (!Schema::hasTable('ahorros_personal')) {
                Schema::rename('ahorros_socio', 'ahorros_personal');
                Schema::rename('ahorro_socio_movimientos', 'ahorro_personal_movimientos');

                Schema::table('ahorro_personal_movimientos', function (Blueprint $table) {
                    $table->dropForeign(['ahorro_socio_id']);
                });

                Schema::table('ahorro_personal_movimientos', function (Blueprint $table) {
                    $table->renameColumn('ahorro_socio_id', 'ahorro_personal_id');
                });

                Schema::table('ahorro_personal_movimientos', function (Blueprint $table) {
                    $table->foreign('ahorro_personal_id')->references('id')->on('ahorros_personal')->cascadeOnDelete();
                });
            } else {
                Schema::dropIfExists('ahorro_socio_movimientos');
                Schema::dropIfExists('ahorros_socio');
            }
        }

        if (!Schema::hasTable('socios')) {
            Schema::create('socios', function (Blueprint $table) {
                $table->id();
                $table->string('nombre');
                $table->string('codigo')->unique();
                $table->boolean('activo')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('ahorros_socio')) {
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

        if (Schema::hasTable('ahorros_personal')) {
            foreach (Asesor::all() as $asesor) {
                DB::table('ahorros_personal')->insertOrIgnore([
                    'asesor_id' => $asesor->id,
                    'saldo' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // No revert automático — migración de separación unidireccional
    }
};
