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

        foreach (['confirmaciones_movimientos', 'movimientos_caja', 'movimientos_capital', 'gastos_operativos', 'ahorros_personal', 'ahorros_socio', 'creditos', 'clientes', 'asesores'] as $table) {
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

        Schema::create('ahorros_personal', function (Blueprint $table) {
            $table->id();
            $table->decimal('saldo', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('ahorros_socio', function (Blueprint $table) {
            $table->id();
            $table->decimal('saldo', 14, 2)->default(0);
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
            'fecha' => '2026-09-05', 'motivo' => 'Gasto por confirmar', 'tipo' => 'Egreso',
            'monto' => 900, 'categoria' => 'Gasto', 'cuenta' => 'Efectivo', 'referencia' => 'PRUEBA-CONF-1',
        ]);

        $this->assertSame(0, MovimientoCaja::count());
        $flujoCaja->confirmarEgresoPendiente($pendiente);

        $this->assertDatabaseHas('confirmaciones_movimientos', ['id' => $pendiente->id, 'estado' => 'Confirmado']);
        $this->assertDatabaseHas('movimientos_caja', ['referencia' => 'PRUEBA-CONF-1', 'tipo' => 'Egreso', 'monto' => 900.00]);
    }

    public static function categoriasDesembolso(): array
    {
        return [['Renovacion'], ['Desembolso']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('categoriasDesembolso')]
    public function test_disbursement_requires_manager_and_field_agent_confirmations_without_duplicate_cash_expense(string $categoria): void
    {
        $credito = Credito::create([
            'id_asesor' => 1, 'fecha_otorgacion' => '2026-09-05', 'monto_otorgado' => 3028,
            'estado' => 'PendienteDesembolso',
        ]);
        $flujoCaja = app(FlujoCajaService::class);
        $pendiente = $flujoCaja->solicitarConfirmacionEgreso([
            'fecha' => '2026-09-05', 'id_asesor' => 1, 'motivo' => 'RENOVACIÓN A 14 SEMANAS — Virginia',
            'tipo' => 'Egreso', 'monto' => 3028, 'categoria' => $categoria, 'cuenta' => 'Efectivo', 'num_prog' => $credito->num_prog,
            'referencia' => 'DESEMBOLSO-REN-1',
        ]);

        $this->assertSame(0, MovimientoCaja::count());
        $entregadoAlGestor = $flujoCaja->confirmarEgresoPendiente($pendiente);

        $this->assertSame('EntregadoGestor', $entregadoAlGestor->estado);
        $this->assertSame(1, MovimientoCaja::where('referencia', 'DESEMBOLSO-REN-1')->count());

        $this->assertSame('PendienteDesembolso', $credito->fresh()->estado);
        $listado = app(\App\Http\Controllers\ConfirmacionMovimientoController::class)->index(
            \Illuminate\Http\Request::create('/', 'GET', ['fecha' => '2026-09-05']),
        );
        $this->assertSame($pendiente->id, $listado->getData(true)[0]['id']);
        $this->assertSame('EntregadoGestor', $listado->getData(true)[0]['estado']);

        $confirmado = $flujoCaja->confirmarDesembolsoRenovacionPorGestor($entregadoAlGestor);

        $this->assertSame('Confirmado', $confirmado->estado);
        $this->assertNotNull($confirmado->entregado_gestor_at);
        $this->assertSame(1, MovimientoCaja::where('referencia', 'DESEMBOLSO-REN-1')->count());
        $this->assertSame('Activo', $credito->fresh()->estado);

        $this->expectException(\InvalidArgumentException::class);
        $flujoCaja->confirmarDesembolsoRenovacionPorGestor($confirmado);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('categoriasDesembolso')]
    public function test_cancelled_disbursement_requires_cash_reintegration_confirmation(string $categoria): void
    {
        $credito = Credito::create([
            'id_asesor' => 1, 'fecha_otorgacion' => '2026-09-05', 'monto_otorgado' => 1500,
            'estado' => 'Activo',
        ]);
        $flujoCaja = app(FlujoCajaService::class);
        $pendiente = $flujoCaja->solicitarConfirmacionEgreso([
            'fecha' => '2026-09-05', 'id_asesor' => 1, 'motivo' => 'RENOVACIÓN NO ENTREGADA',
            'tipo' => 'Egreso', 'monto' => 1500, 'categoria' => $categoria, 'cuenta' => 'Efectivo',
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
            'categoria' => $categoria === 'Renovacion' ? 'ReintegroRenovacion' : 'ReintegroDesembolso',
        ]);

        $reprogramado = $flujoCaja->reprogramarDesembolsoRenovacion($reintegrado, '2026-09-10');
        $this->assertSame('Pendiente', $reprogramado->estado);
        $this->assertSame($categoria, $reprogramado->categoria);
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

    public function test_correcting_a_disbursement_date_moves_its_confirmation_and_recalculates_balances(): void
    {
        $flujoCaja = app(FlujoCajaService::class);
        $saldoInicial = $flujoCaja->registrar([
            'fecha' => '2026-09-01', 'motivo' => 'Saldo inicial', 'tipo' => 'Ingreso',
            'monto' => 10000, 'categoria' => 'SaldoInicial', 'cuenta' => 'Efectivo',
        ]);
        $confirmacion = $flujoCaja->solicitarConfirmacionEgreso([
            'fecha' => '2026-09-14', 'motivo' => 'RENOVACIÓN A 16 SEMANAS', 'tipo' => 'Egreso',
            'monto' => 8302, 'categoria' => 'Renovacion', 'cuenta' => 'Efectivo',
            'referencia' => 'DESEMBOLSO-99',
        ]);
        $confirmada = $flujoCaja->confirmarEgresoPendiente($confirmacion);
        $ingresoIntermedio = $flujoCaja->registrar([
            'fecha' => '2026-09-14', 'motivo' => 'Ingreso del día', 'tipo' => 'Ingreso',
            'monto' => 500, 'categoria' => 'OtroIngreso', 'cuenta' => 'Efectivo',
        ]);
        $movimiento = MovimientoCaja::findOrFail($confirmada->movimiento_caja_id);

        $corregido = $flujoCaja->corregirFechaDesembolso($movimiento, '2026-09-15');

        $this->assertSame('2026-09-15', $corregido->fecha->toDateString());
        $this->assertSame('2026-09-15', $confirmacion->fresh()->fecha->toDateString());
        $this->assertSame(10500.0, (float) $ingresoIntermedio->fresh()->saldo_resultante);
        $this->assertSame(2198.0, (float) $corregido->fresh()->saldo_resultante);
        $this->assertSame(10000.0, (float) $saldoInicial->fresh()->saldo_resultante);
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

    public function test_daily_summary_starts_from_the_previous_days_passive_capital(): void
    {
        MovimientoCaja::create([
            'fecha' => '2026-09-02',
            'motivo' => 'Cierre del día anterior',
            'tipo' => 'Ingreso',
            'monto' => 1000,
            'saldo_resultante' => 1000,
        ]);
        MovimientoCaja::create([
            'fecha' => '2026-09-03',
            'motivo' => 'Ingreso del día',
            'tipo' => 'Ingreso',
            'monto' => 200,
            'saldo_resultante' => 1200,
        ]);
        MovimientoCaja::create([
            'fecha' => '2026-09-03',
            'motivo' => 'Egreso del día',
            'tipo' => 'Egreso',
            'monto' => 50,
            'saldo_resultante' => 1150,
        ]);
        MovimientoCaja::create([
            'fecha' => '2026-09-04',
            'motivo' => 'Movimiento posterior',
            'tipo' => 'Ingreso',
            'monto' => 300,
            'saldo_resultante' => 1450,
        ]);

        $resumen = app(FlujoCajaService::class)->resumen(fecha: '2026-09-03');

        $this->assertSame(1000.0, $resumen['saldo_inicial_mes']);
        $this->assertSame(200.0, $resumen['total_ingresos']);
        $this->assertSame(50.0, $resumen['total_egresos']);
        $this->assertSame(1150.0, $resumen['disponible']);
        $this->assertSame(1150.0, $resumen['saldo_actual']);
    }

    public function test_daily_summary_uses_an_explicit_initial_balance_instead_of_the_previous_day(): void
    {
        MovimientoCaja::create([
            'fecha' => '2026-08-31',
            'motivo' => 'Cierre de agosto',
            'tipo' => 'Ingreso',
            'monto' => 900,
            'saldo_resultante' => 900,
        ]);
        MovimientoCaja::create([
            'fecha' => '2026-09-01',
            'motivo' => 'Saldo inicial de septiembre',
            'tipo' => 'Ingreso',
            'monto' => 1200,
            'saldo_resultante' => 1200,
            'categoria' => 'SaldoInicial',
        ]);
        MovimientoCaja::create([
            'fecha' => '2026-09-01',
            'motivo' => 'Ingreso del día',
            'tipo' => 'Ingreso',
            'monto' => 100,
            'saldo_resultante' => 1300,
        ]);
        MovimientoCaja::create([
            'fecha' => '2026-09-01',
            'motivo' => 'Egreso del día',
            'tipo' => 'Egreso',
            'monto' => 50,
            'saldo_resultante' => 1250,
        ]);

        $resumen = app(FlujoCajaService::class)->resumen(fecha: '2026-09-01');

        $this->assertSame(900.0, $resumen['saldo_anterior']);
        $this->assertSame(1200.0, $resumen['saldo_inicial_mes']);
        $this->assertSame(1250.0, $resumen['disponible']);
    }

    public function test_after_day_one_the_opening_balance_is_the_previous_close_not_the_explicit_initial_balance(): void
    {
        MovimientoCaja::create([
            'fecha' => '2026-09-01',
            'motivo' => 'Saldo inicial de septiembre',
            'tipo' => 'Ingreso',
            'monto' => 1200,
            'saldo_resultante' => 1200,
            'categoria' => 'SaldoInicial',
        ]);
        MovimientoCaja::create([
            'fecha' => '2026-09-01',
            'motivo' => 'Ingreso del primer día',
            'tipo' => 'Ingreso',
            'monto' => 100,
            'saldo_resultante' => 1300,
        ]);
        MovimientoCaja::create([
            'fecha' => '2026-09-01',
            'motivo' => 'Egreso del primer día',
            'tipo' => 'Egreso',
            'monto' => 50,
            'saldo_resultante' => 1250,
        ]);
        MovimientoCaja::create([
            'fecha' => '2026-09-02',
            'motivo' => 'Ingreso del segundo día',
            'tipo' => 'Ingreso',
            'monto' => 80,
            'saldo_resultante' => 1330,
        ]);

        $resumen = app(FlujoCajaService::class)->resumen(fecha: '2026-09-02');

        $this->assertSame(1250.0, $resumen['saldo_inicial_mes']);
        $this->assertSame(80.0, $resumen['total_ingresos']);
        $this->assertSame(1330.0, $resumen['disponible']);
    }

    public function test_recalculation_applies_the_explicit_initial_balance_before_other_first_day_movements(): void
    {
        $ingreso = MovimientoCaja::create([
            'fecha' => '2026-09-01',
            'motivo' => 'Ingreso capturado antes del saldo inicial',
            'tipo' => 'Ingreso',
            'monto' => 100,
        ]);
        $saldoInicial = MovimientoCaja::create([
            'fecha' => '2026-09-01',
            'motivo' => 'Saldo inicial capturado después',
            'tipo' => 'Ingreso',
            'monto' => 1000,
            'categoria' => 'SaldoInicial',
        ]);

        app(FlujoCajaService::class)->recalcularSaldosDesde('2026-09-01');

        $this->assertSame(1000.0, (float) $saldoInicial->fresh()->saldo_resultante);
        $this->assertSame(1100.0, (float) $ingreso->fresh()->saldo_resultante);
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
