<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Credito;
use App\Models\MovimientoCaja;
use App\Services\FlujoCajaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FlujoCajaDesembolsoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['movimientos_caja', 'creditos', 'clientes', 'asesores'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('asesores', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_asesor')->nullable();
            $table->timestamps();
        });

        Schema::create('clientes', function (Blueprint $table) {
            $table->string('id_cliente')->primary();
            $table->string('nombre_completo')->nullable();
            $table->timestamps();
        });

        Schema::create('creditos', function (Blueprint $table) {
            $table->id('num_prog');
            $table->string('id_cliente')->nullable();
            $table->unsignedBigInteger('id_grupo')->nullable();
            $table->unsignedBigInteger('id_asesor')->nullable();
            $table->date('fecha_otorgacion');
            $table->decimal('monto_otorgado', 12, 2);
            $table->decimal('comision_apertura', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('movimientos_caja', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->unsignedBigInteger('id_asesor')->nullable();
            $table->text('motivo');
            $table->string('tipo');
            $table->decimal('monto', 14, 2);
            $table->decimal('saldo_resultante', 14, 2)->nullable();
            $table->string('categoria')->nullable();
            $table->string('cuenta')->nullable();
            $table->unsignedBigInteger('num_prog')->nullable();
            $table->unsignedBigInteger('pago_id')->nullable();
            $table->string('referencia')->nullable();
            $table->unsignedBigInteger('registrado_por')->nullable();
            $table->timestamps();
        });
    }

    public function test_syncing_a_disbursement_updates_its_existing_movement_without_duplicates(): void
    {
        Cliente::create(['id_cliente' => 'CLI-001', 'nombre_completo' => 'Virginia']);
        $credito = Credito::create([
            'id_cliente' => 'CLI-001',
            'id_asesor' => 1,
            'fecha_otorgacion' => '2026-09-05',
            'monto_otorgado' => 1000,
            'comision_apertura' => 100,
        ]);
        $flujoCaja = app(FlujoCajaService::class);

        $flujoCaja->registrarDesdeDesembolso($credito, 900);
        $credito->update(['monto_otorgado' => 1500]);
        $flujoCaja->sincronizarDesembolso($credito, 1400);

        $referencia = "DESEMBOLSO-{$credito->num_prog}";
        $this->assertSame(1, MovimientoCaja::where('referencia', $referencia)->count());
        $this->assertDatabaseHas('movimientos_caja', [
            'referencia' => $referencia,
            'tipo' => 'Egreso',
            'monto' => 1400.00,
        ]);
    }

    public function test_deleting_a_manual_movement_recalculates_following_balances(): void
    {
        $saldoInicial = MovimientoCaja::create([
            'fecha' => '2026-09-01',
            'motivo' => 'Saldo inicial',
            'tipo' => 'Ingreso',
            'monto' => 100,
            'saldo_resultante' => 100,
            'categoria' => 'SaldoInicial',
        ]);
        $movimientoErroneo = MovimientoCaja::create([
            'fecha' => '2026-09-02',
            'motivo' => 'Ingreso capturado por error',
            'tipo' => 'Ingreso',
            'monto' => 50,
            'saldo_resultante' => 150,
        ]);
        $egresoPosterior = MovimientoCaja::create([
            'fecha' => '2026-09-03',
            'motivo' => 'Compra posterior',
            'tipo' => 'Egreso',
            'monto' => 20,
            'saldo_resultante' => 130,
        ]);

        app(FlujoCajaService::class)->eliminar($movimientoErroneo);

        $this->assertDatabaseMissing('movimientos_caja', ['id' => $movimientoErroneo->id]);
        $this->assertDatabaseHas('movimientos_caja', [
            'id' => $saldoInicial->id,
            'saldo_resultante' => 100.00,
        ]);
        $this->assertDatabaseHas('movimientos_caja', [
            'id' => $egresoPosterior->id,
            'saldo_resultante' => 80.00,
        ]);
    }

    public function test_deleting_an_automatic_movement_is_not_allowed(): void
    {
        $movimiento = MovimientoCaja::create([
            'fecha' => '2026-09-01',
            'motivo' => 'Gasto operativo',
            'tipo' => 'Egreso',
            'monto' => 100,
            'referencia' => 'GASTO-1',
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(FlujoCajaService::class)->eliminar($movimiento);
    }
}
