<?php

namespace Tests\Feature;

use App\Models\GastoOperativo;
use App\Models\MovimientoCaja;
use App\Models\MovimientoCapital;
use App\Services\CapitalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CapitalServiceGastoDeletionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['confirmaciones_movimientos', 'movimientos_caja', 'movimientos_capital', 'gastos_operativos'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('gastos_operativos', function (Blueprint $table) {
            $table->id();
            $table->string('concepto');
            $table->decimal('monto', 12, 2);
            $table->date('fecha');
            $table->string('categoria')->nullable();
            $table->timestamps();
        });

        Schema::create('movimientos_capital', function (Blueprint $table) {
            $table->id();
            $table->string('tipo');
            $table->decimal('monto', 14, 2);
            $table->string('referencia')->nullable();
            $table->date('fecha');
            $table->timestamps();
        });

        Schema::create('movimientos_caja', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->text('motivo');
            $table->string('tipo');
            $table->decimal('monto', 14, 2);
            $table->decimal('saldo_resultante', 14, 2)->nullable();
            $table->string('categoria')->nullable();
            $table->string('referencia')->nullable();
            $table->timestamps();
        });
        Schema::create('confirmaciones_movimientos', function (Blueprint $table) {
            $table->id(); $table->date('fecha'); $table->text('motivo'); $table->decimal('monto', 14, 2);
            $table->string('referencia')->nullable(); $table->string('estado')->default('Pendiente'); $table->timestamps();
        });
    }

    public function test_deleting_a_gasto_removes_its_related_movements_and_recalculates_cash(): void
    {
        MovimientoCaja::create([
            'fecha' => '2026-09-01',
            'motivo' => 'Saldo inicial',
            'tipo' => 'Ingreso',
            'monto' => 100,
            'saldo_resultante' => 100,
            'categoria' => 'SaldoInicial',
        ]);
        $gasto = GastoOperativo::create([
            'concepto' => 'Pago de rendimientos',
            'monto' => 30,
            'fecha' => '2026-09-02',
            'categoria' => 'ADEUDOS',
        ]);
        $referencia = "GASTO-{$gasto->id}";
        MovimientoCapital::create([
            'tipo' => 'Gasto',
            'monto' => -30,
            'referencia' => $referencia,
            'fecha' => '2026-09-02',
        ]);
        MovimientoCaja::create([
            'fecha' => '2026-09-02',
            'motivo' => 'Pago de rendimientos',
            'tipo' => 'Egreso',
            'monto' => 30,
            'saldo_resultante' => 70,
            'categoria' => 'ADEUDOS',
            'referencia' => $referencia,
        ]);
        $movimientoPosterior = MovimientoCaja::create([
            'fecha' => '2026-09-03',
            'motivo' => 'Cobro posterior',
            'tipo' => 'Ingreso',
            'monto' => 20,
            'saldo_resultante' => 90,
        ]);

        app(CapitalService::class)->eliminarGasto($gasto);

        $this->assertDatabaseMissing('gastos_operativos', ['id' => $gasto->id]);
        $this->assertDatabaseMissing('movimientos_capital', ['referencia' => $referencia]);
        $this->assertDatabaseMissing('movimientos_caja', ['referencia' => $referencia]);
        $this->assertDatabaseHas('movimientos_caja', [
            'id' => $movimientoPosterior->id,
            'saldo_resultante' => 120.00,
        ]);
    }
}
