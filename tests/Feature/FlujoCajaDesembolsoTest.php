<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Credito;
use App\Models\GastoOperativo;
use App\Models\MovimientoCaja;
use App\Models\MovimientoCapital;
use App\Models\ConfirmacionMovimiento;
use App\Services\FlujoCajaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FlujoCajaDesembolsoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['confirmaciones_movimientos', 'movimientos_caja', 'movimientos_capital', 'gastos_operativos', 'creditos', 'clientes', 'asesores'] as $table) {
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
            $table->string('estado')->default('Activo');
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

        Schema::create('confirmaciones_movimientos', function (Blueprint $table) {
            $table->id(); $table->date('fecha'); $table->unsignedBigInteger('id_asesor')->nullable(); $table->text('motivo');
            $table->decimal('monto', 14, 2); $table->string('categoria')->nullable(); $table->string('cuenta')->nullable();
            $table->unsignedBigInteger('num_prog')->nullable(); $table->string('referencia')->nullable()->unique();
            $table->string('estado')->default('Pendiente'); $table->unsignedBigInteger('movimiento_caja_id')->nullable(); $table->unsignedBigInteger('movimiento_reintegro_id')->nullable();
            $table->unsignedBigInteger('solicitado_por')->nullable(); $table->unsignedBigInteger('confirmado_por')->nullable(); $table->timestamp('confirmado_at')->nullable();
            $table->unsignedBigInteger('entregado_gestor_por')->nullable(); $table->timestamp('entregado_gestor_at')->nullable();
            $table->unsignedBigInteger('cancelado_gestor_por')->nullable(); $table->timestamp('cancelado_gestor_at')->nullable();
            $table->unsignedBigInteger('reintegrado_por')->nullable(); $table->timestamp('reintegrado_at')->nullable(); $table->timestamps();
        });

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
        $this->assertSame(0, MovimientoCaja::where('referencia', $referencia)->count());
        $this->assertDatabaseHas('confirmaciones_movimientos', [
            'referencia' => $referencia,
            'monto' => 1400.00,
            'estado' => 'Pendiente',
        ]);
    }

    public function test_confirming_a_pending_expense_is_when_it_affects_cash(): void
    {
        $flujoCaja = app(FlujoCajaService::class);
        $pendiente = $flujoCaja->solicitarConfirmacionEgreso([
            'fecha' => '2026-09-05', 'motivo' => 'Desembolso por confirmar', 'tipo' => 'Egreso',
            'monto' => 900, 'categoria' => 'Desembolso', 'cuenta' => 'Efectivo', 'referencia' => 'PRUEBA-CONF-1',
        ]);

        $this->assertSame(0, MovimientoCaja::count());
        $flujoCaja->confirmarEgresoPendiente($pendiente);

        $this->assertDatabaseHas('confirmaciones_movimientos', ['id' => $pendiente->id, 'estado' => 'Confirmado']);
        $this->assertDatabaseHas('movimientos_caja', ['referencia' => 'PRUEBA-CONF-1', 'tipo' => 'Egreso', 'monto' => 900.00]);
    }

    public function test_renewal_requires_manager_and_field_agent_confirmations_without_duplicate_cash_expense(): void
    {
        $credito = Credito::create([
            'id_asesor' => 1, 'fecha_otorgacion' => '2026-09-05', 'monto_otorgado' => 3028,
            'estado' => 'PendienteDesembolso',
        ]);
        $flujoCaja = app(FlujoCajaService::class);
        $pendiente = $flujoCaja->solicitarConfirmacionEgreso([
            'fecha' => '2026-09-05', 'id_asesor' => 1, 'motivo' => 'RENOVACIÓN A 14 SEMANAS — Virginia',
            'tipo' => 'Egreso', 'monto' => 3028, 'categoria' => 'Renovacion', 'cuenta' => 'Efectivo', 'num_prog' => $credito->num_prog,
            'referencia' => 'DESEMBOLSO-REN-1',
        ]);

        $entregadoAlGestor = $flujoCaja->confirmarEgresoPendiente($pendiente);

        $this->assertSame('EntregadoGestor', $entregadoAlGestor->estado);
        $this->assertSame(1, MovimientoCaja::where('referencia', 'DESEMBOLSO-REN-1')->count());

        $confirmado = $flujoCaja->confirmarDesembolsoRenovacionPorGestor($entregadoAlGestor);

        $this->assertSame('Confirmado', $confirmado->estado);
        $this->assertNotNull($confirmado->entregado_gestor_at);
        $this->assertSame(1, MovimientoCaja::where('referencia', 'DESEMBOLSO-REN-1')->count());
        $this->assertSame('Activo', $credito->fresh()->estado);
    }

    public function test_cancelled_renewal_requires_cash_reintegration_confirmation(): void
    {
        $credito = Credito::create([
            'id_asesor' => 1, 'fecha_otorgacion' => '2026-09-05', 'monto_otorgado' => 1500,
            'estado' => 'Activo',
        ]);
        $flujoCaja = app(FlujoCajaService::class);
        $pendiente = $flujoCaja->solicitarConfirmacionEgreso([
            'fecha' => '2026-09-05', 'id_asesor' => 1, 'motivo' => 'RENOVACIÓN NO ENTREGADA',
            'tipo' => 'Egreso', 'monto' => 1500, 'categoria' => 'Renovacion', 'cuenta' => 'Efectivo',
            'referencia' => 'DESEMBOLSO-REN-CANCELADO', 'num_prog' => $credito->num_prog,
        ]);

        $entregadoAlGestor = $flujoCaja->confirmarEgresoPendiente($pendiente);
        $pendienteReintegro = $flujoCaja->cancelarDesembolsoRenovacionPorGestor($entregadoAlGestor);

        $this->assertSame('PendienteReintegro', $pendienteReintegro->estado);
        $this->assertNotNull($pendienteReintegro->cancelado_gestor_at);
        $this->assertSame('PendienteDesembolso', $credito->fresh()->estado);
        $this->assertSame(1, MovimientoCaja::where('referencia', 'DESEMBOLSO-REN-CANCELADO')->count());

        $reintegrado = $flujoCaja->confirmarReintegroRenovacion($pendienteReintegro);

        $this->assertSame('Reintegrado', $reintegrado->estado);
        $this->assertNotNull($reintegrado->reintegrado_at);
        $this->assertDatabaseHas('movimientos_caja', [
            'referencia' => "REINTEGRO-RENOVACION-{$pendiente->id}",
            'tipo' => 'Ingreso',
            'monto' => 1500.00,
            'categoria' => 'ReintegroRenovacion',
        ]);

        $reprogramado = $flujoCaja->reprogramarDesembolsoRenovacion($reintegrado, '2026-09-10');
        $this->assertSame('Pendiente', $reprogramado->estado);
        $this->assertSame('2026-09-10', $reprogramado->fecha->toDateString());
        $this->assertSame('Reprogramado', $reintegrado->fresh()->estado);
        $this->assertSame('PendienteDesembolso', $credito->fresh()->estado);
    }

    public function test_syncing_a_restructured_delivery_replaces_5028_with_3028(): void
    {
        Cliente::create(['id_cliente' => 'CLI-REN', 'nombre_completo' => 'Renovación']);
        $credito = Credito::create([
            'id_cliente' => 'CLI-REN',
            'id_asesor' => 1,
            'fecha_otorgacion' => '2026-09-05',
            'monto_otorgado' => 6628,
            'comision_apertura' => 100,
        ]);
        $flujoCaja = app(FlujoCajaService::class);

        $flujoCaja->registrarDesdeDesembolso($credito, 5028);
        $credito->update(['monto_otorgado' => 4628]);
        $flujoCaja->sincronizarDesembolso(
            $credito,
            3028,
            'RENOVACIÓN A 14 SEMANAS — Renovación',
            'Renovacion',
        );

        $referencia = "DESEMBOLSO-{$credito->num_prog}";
        $this->assertSame(0, MovimientoCaja::where('referencia', $referencia)->count());
        $this->assertDatabaseHas('confirmaciones_movimientos', [
            'referencia' => $referencia,
            'monto' => 3028.00,
            'motivo' => 'RENOVACIÓN A 14 SEMANAS — Renovación',
            'categoria' => 'Renovacion',
        ]);
    }

    public function test_updating_group_commission_updates_the_existing_disbursement(): void
    {
        Cliente::create(['id_cliente' => 'CLI-GRP', 'nombre_completo' => 'Grupo prueba']);
        $credito = Credito::create([
            'id_cliente' => 'CLI-GRP',
            'id_asesor' => 1,
            'fecha_otorgacion' => '2026-09-05',
            'monto_otorgado' => 10000,
            'comision_apertura' => 100,
        ]);
        $flujoCaja = app(FlujoCajaService::class);

        $flujoCaja->registrarDesdeDesembolso($credito, 9900);
        $credito->update(['comision_apertura' => 300]); // tres integrantes × $100
        $flujoCaja->sincronizarDesembolso($credito, 9700);

        $this->assertDatabaseHas('confirmaciones_movimientos', [
            'referencia' => "DESEMBOLSO-{$credito->num_prog}",
            'monto' => 9700.00,
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

    public function test_initial_balance_is_the_month_base_and_later_movements_do_not_replace_it(): void
    {
        $flujoCaja = app(FlujoCajaService::class);

        $saldoInicial = $flujoCaja->registrar([
            'fecha' => '2026-09-01',
            'motivo' => 'Saldo inicial de septiembre',
            'tipo' => 'Ingreso',
            'monto' => 10000,
            'categoria' => 'SaldoInicial',
        ]);
        $ingreso = $flujoCaja->registrar([
            'fecha' => '2026-09-02',
            'motivo' => 'Cobro del día',
            'tipo' => 'Ingreso',
            'monto' => 500,
        ]);
        $egreso = $flujoCaja->registrar([
            'fecha' => '2026-09-03',
            'motivo' => 'Gasto del día',
            'tipo' => 'Egreso',
            'monto' => 200,
        ]);

        $this->assertSame(10000.0, (float) $saldoInicial->saldo_resultante);
        $this->assertSame(10500.0, (float) $ingreso->saldo_resultante);
        $this->assertSame(10300.0, (float) $egreso->saldo_resultante);
    }

    public function test_initial_balance_must_be_unique_and_registered_on_the_first_day_of_the_month(): void
    {
        $flujoCaja = app(FlujoCajaService::class);

        $this->expectException(\InvalidArgumentException::class);
        $flujoCaja->registrar([
            'fecha' => '2026-09-02',
            'motivo' => 'Saldo inicial fuera de fecha',
            'tipo' => 'Ingreso',
            'monto' => 10000,
            'categoria' => 'SaldoInicial',
        ]);
    }

    public function test_cannot_register_a_second_initial_balance_in_the_same_month(): void
    {
        $flujoCaja = app(FlujoCajaService::class);
        $flujoCaja->registrar([
            'fecha' => '2026-09-01',
            'motivo' => 'Saldo inicial de septiembre',
            'tipo' => 'Ingreso',
            'monto' => 10000,
            'categoria' => 'SaldoInicial',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $flujoCaja->registrar([
            'fecha' => '2026-09-01',
            'motivo' => 'Segundo saldo inicial',
            'tipo' => 'Ingreso',
            'monto' => 5000,
            'categoria' => 'SaldoInicial',
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

    public function test_deleting_a_rendimiento_recorded_as_gasto_removes_its_source_records(): void
    {
        $gasto = GastoOperativo::create([
            'concepto' => 'PAGO RENDIMIENTOS',
            'monto' => 4250,
            'fecha' => '2026-09-01',
            'categoria' => 'RENDIMIENTOS',
        ]);
        $referencia = "GASTO-{$gasto->id}";
        MovimientoCapital::create([
            'tipo' => 'Gasto',
            'monto' => -4250,
            'referencia' => $referencia,
            'fecha' => '2026-09-01',
        ]);
        $movimiento = MovimientoCaja::create([
            'fecha' => '2026-09-01',
            'motivo' => 'PAGO RENDIMIENTOS',
            'tipo' => 'Egreso',
            'monto' => 4250,
            'categoria' => 'RENDIMIENTOS',
            'referencia' => $referencia,
        ]);

        app(FlujoCajaService::class)->eliminar($movimiento);

        $this->assertDatabaseMissing('movimientos_caja', ['id' => $movimiento->id]);
        $this->assertDatabaseMissing('gastos_operativos', ['id' => $gasto->id]);
        $this->assertDatabaseMissing('movimientos_capital', ['referencia' => $referencia]);
    }
}
