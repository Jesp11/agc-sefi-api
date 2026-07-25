<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creditos', function (Blueprint $table) {
            if (!Schema::hasColumn('creditos', 'ciclo_inicio_mora')) {
                $table->integer('ciclo_inicio_mora')->nullable()->after('ciclo');
            }
            if (!Schema::hasColumn('creditos', 'abono_recuperacion')) {
                $table->decimal('abono_recuperacion', 12, 2)->nullable()->after('ciclo_inicio_mora');
            }
            if (!Schema::hasColumn('creditos', 'comision_apertura')) {
                $table->decimal('comision_apertura', 12, 2)->default(100.00)->after('abono_recuperacion');
            }
            if (!Schema::hasColumn('creditos', 'saldo_pendiente')) {
                $table->decimal('saldo_pendiente', 12, 2)->nullable()->after('total');
            }
            if (!Schema::hasColumn('creditos', 'credito_padre_id')) {
                $table->unsignedBigInteger('credito_padre_id')->nullable()->after('saldo_pendiente');
            }
            if (!Schema::hasColumn('creditos', 'es_adicional')) {
                $table->boolean('es_adicional')->default(false)->after('es_personalizado');
            }
            if (!Schema::hasColumn('creditos', 'dias_mora_cache')) {
                $table->integer('dias_mora_cache')->default(0)->after('es_adicional');
            }
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE creditos MODIFY COLUMN estado ENUM('Activo','Finalizado','Cancelado','EnMora','CerradoSinRenovacion') NOT NULL DEFAULT 'Activo'");
        }

        if (Schema::hasColumn('creditos', 'credito_padre_id')) {
            try {
                Schema::table('creditos', function (Blueprint $table) {
                    $table->foreign('credito_padre_id')->references('num_prog')->on('creditos')->nullOnDelete();
                });
            } catch (\Throwable) {
                // Foreign key may already exist from a partial migration run
            }
        }

        if (Schema::hasColumn('creditos', 'saldo_pendiente')) {
            DB::table('creditos')->whereNull('saldo_pendiente')->update([
                'saldo_pendiente' => DB::raw('total'),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('creditos', function (Blueprint $table) {
            if (Schema::hasColumn('creditos', 'credito_padre_id')) {
                try {
                    $table->dropForeign(['credito_padre_id']);
                } catch (\Throwable) {
                    // FK may not exist
                }
            }
            $columns = array_filter([
                'ciclo_inicio_mora', 'abono_recuperacion', 'comision_apertura',
                'saldo_pendiente', 'credito_padre_id', 'es_adicional', 'dias_mora_cache',
            ], fn ($col) => Schema::hasColumn('creditos', $col));

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE creditos MODIFY COLUMN estado ENUM('Activo','Finalizado','Cancelado') NOT NULL DEFAULT 'Activo'");
        }
    }
};
