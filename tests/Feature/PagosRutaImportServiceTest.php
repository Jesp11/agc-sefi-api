<?php

namespace Tests\Feature;

use App\Models\Asesor;
use App\Models\Cliente;
use App\Models\Credito;
use App\Models\MovimientoCaja;
use App\Models\Pago;
use App\Services\FlujoCajaService;
use App\Services\PagoService;
use App\Services\PagosRutaImportService;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PagosRutaImportServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        Carbon::setTestNow('2026-09-05 10:00:00'); // sábado
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_creates_a_payment_without_cash_movement_then_skips_the_same_route_reference(): void
    {
        $credito = $this->credito();
        $row = $this->row($credito);
        $service = app(PagosRutaImportService::class);

        $preview = $service->previsualizar('2026-09-05', [$row], array_keys($row));
        $this->assertSame(0, $preview['summary']['invalid']);
        $this->assertSame(1, $preview['summary']['created']);

        $result = $service->confirmar('2026-09-05', [$row], array_keys($row));
        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('pagos', [
            'num_prog' => $credito->num_prog,
            'monto' => 100,
            'referencia_importacion' => $row['referencia_ruta'],
        ]);
        $this->assertSame(0, MovimientoCaja::count());
        $this->assertSame(100.0, (float) $credito->fresh()->saldo_pendiente);

        $again = $service->previsualizar('2026-09-05', [$row], array_keys($row));
        $this->assertSame(0, $again['summary']['invalid']);
        $this->assertSame(1, $again['summary']['omitted']);
        $this->assertSame(1, Pago::count());
    }

    public function test_ignores_no_rows_and_rejects_modified_amount_or_method(): void
    {
        $credito = $this->credito();
        $service = app(PagosRutaImportService::class);
        $noRow = [...$this->row($credito), 'pago_realizado' => 'NO'];
        $ignored = $service->confirmar('2026-09-05', [$noRow], array_keys($noRow));
        $this->assertSame(0, $ignored['created']);
        $this->assertSame(0, Pago::count());

        $invalid = [...$this->row($credito), 'importe_esperado' => 99, 'metodo_pago' => 'Tarjeta'];
        $preview = $service->previsualizar('2026-09-05', [$invalid], array_keys($invalid));
        $this->assertSame(1, $preview['summary']['invalid']);
        $this->assertNotEmpty($preview['rows'][0]['errors']);
    }

    public function test_skips_a_quota_already_paid_manually(): void
    {
        $credito = $this->credito();
        $row = $this->row($credito);
        Pago::create([
            'num_prog' => $credito->num_prog,
            'monto' => 100,
            'fecha' => '2026-09-05',
            'hora' => '09:00:00',
            'tipo' => 'Abono',
            'metodo_pago' => 'Efectivo',
        ]);

        $preview = app(PagosRutaImportService::class)->previsualizar('2026-09-05', [$row], array_keys($row));

        $this->assertSame(0, $preview['summary']['invalid']);
        $this->assertSame(1, $preview['summary']['omitted']);
        $this->assertStringContainsString('pago manual', $preview['rows'][0]['warnings'][0]);
    }

    public function test_syncing_a_historical_payment_creates_and_then_updates_one_cash_income(): void
    {
        $credito = $this->credito();
        $pago = Pago::create([
            'num_prog' => $credito->num_prog,
            'monto' => 873,
            'fecha' => '2026-08-29',
            'hora' => '10:00:00',
            'tipo' => 'Abono',
            'metodo_pago' => 'Efectivo',
        ]);
        $flujoCaja = app(FlujoCajaService::class);

        $flujoCaja->sincronizarDesdePago($pago, $credito);

        $this->assertDatabaseHas('movimientos_caja', [
            'pago_id' => $pago->id,
            'tipo' => 'Ingreso',
            'monto' => 873,
            'cuenta' => 'Efectivo',
        ]);

        $pago->update([
            'monto' => 950,
            'fecha' => '2026-09-05',
            'metodo_pago' => 'Transferencia',
        ]);
        $flujoCaja->sincronizarDesdePago($pago->fresh(), $credito);

        $this->assertSame(1, MovimientoCaja::where('pago_id', $pago->id)->count());
        $this->assertSame(1, MovimientoCaja::query()
            ->where('pago_id', $pago->id)
            ->whereDate('fecha', '2026-09-05')
            ->where('monto', 950)
            ->where('cuenta', 'Bancomer')
            ->count());
    }

    public function test_cash_income_is_created_only_after_the_administrator_receives_route_money(): void
    {
        $credito = $this->credito();
        $result = app(PagoService::class)->registrar($credito, [
            'fecha' => '2026-09-05',
            'hora' => '10:00:00',
            'monto' => 100,
            'metodo_pago' => 'Efectivo',
        ]);
        $pago = $result['pago'];

        $this->assertSame(0, MovimientoCaja::count());

        $reportes = app(ReportService::class);
        $reportes->registrarRecepcionAsesor('2026-09-05', $credito->id_asesor, 60);

        $this->assertSame(1, MovimientoCaja::query()
            ->where('pago_id', $pago->id)
            ->whereDate('fecha', '2026-09-05')
            ->where('tipo', 'Ingreso')
            ->where('monto', 60)
            ->where('referencia', "RECEPCION-PAGO-{$pago->id}")
            ->count());

        // "Agregar" suma sólo el efectivo nuevo y ajusta el movimiento sin duplicarlo.
        $reportes->registrarRecepcionAsesor('2026-09-05', $credito->id_asesor, 40, null, true);
        $this->assertSame(1, MovimientoCaja::where('pago_id', $pago->id)->count());
        $this->assertSame(100.0, (float) MovimientoCaja::where('pago_id', $pago->id)->value('monto'));
    }

    public function test_full_receipt_allocates_cash_to_advance_payments_without_duplicates(): void
    {
        $creditoRuta = $this->credito();
        $creditoAnticipado = Credito::create([
            'id_cliente' => $creditoRuta->id_cliente,
            'id_asesor' => $creditoRuta->id_asesor,
            'fecha_otorgacion' => '2026-09-05',
            'fecha_primer_pago' => '2026-09-12',
            'ciclo' => 1,
            'monto_otorgado' => 872,
            'interes' => 0,
            'total' => 872,
            'saldo_pendiente' => 872,
            'plazos' => 2,
            'valor_ficha' => 436,
            'dias_pago' => 'SABADO',
            'tipo_credito' => 'Individual',
            'estado' => 'Activo',
        ]);
        $pagoAnticipado = Pago::create([
            'num_prog' => $creditoAnticipado->num_prog,
            'monto' => 436,
            'fecha' => '2026-09-05',
            'hora' => '09:00:00',
            'tipo' => 'Abono',
            'metodo_pago' => 'Efectivo',
        ]);
        $pagoRuta = Pago::create([
            'num_prog' => $creditoRuta->num_prog,
            'monto' => 100,
            'fecha' => '2026-09-05',
            'hora' => '10:00:00',
            'tipo' => 'Abono',
            'metodo_pago' => 'Efectivo',
        ]);
        $reportes = app(ReportService::class);

        // Aunque el anticipado se capturó antes, primero se asigna la ruta.
        $reportes->registrarRecepcionAsesor('2026-09-05', $creditoRuta->id_asesor, 100);
        $this->assertDatabaseHas('movimientos_caja', ['pago_id' => $pagoRuta->id, 'monto' => 100]);
        $this->assertDatabaseMissing('movimientos_caja', ['pago_id' => $pagoAnticipado->id]);

        $reportes->registrarRecepcionAsesor('2026-09-05', $creditoRuta->id_asesor, 436, agregar: true);

        $this->assertDatabaseHas('movimientos_caja', ['pago_id' => $pagoAnticipado->id, 'monto' => 436]);
        $this->assertSame(2, MovimientoCaja::count());
        $this->assertSame(536.0, (float) MovimientoCaja::sum('monto'));
        $this->assertDatabaseHas('recepciones_asesor', [
            'id_asesor' => $creditoRuta->id_asesor,
            'monto_recibido' => 536,
            'monto_esperado' => 536,
        ]);
    }

    public function test_unreceived_and_received_payments_can_be_removed(): void
    {
        $credito = $this->credito();
        $pago = Pago::create([
            'num_prog' => $credito->num_prog,
            'monto' => 100,
            'fecha' => '2026-09-05',
            'hora' => '10:00:00',
            'tipo' => 'Abono',
            'metodo_pago' => 'Efectivo',
        ]);

        app(PagoService::class)->eliminarAbono($credito, $pago);
        $this->assertDatabaseMissing('pagos', ['id' => $pago->id]);
        $this->assertSame(200.0, (float) $credito->fresh()->saldo_pendiente);

        $recibido = Pago::create([
            'num_prog' => $credito->num_prog,
            'monto' => 100,
            'fecha' => '2026-09-05',
            'hora' => '10:30:00',
            'tipo' => 'Abono',
            'metodo_pago' => 'Efectivo',
        ]);
        app(FlujoCajaService::class)->registrarDesdePago($recibido, $credito);

        app(PagoService::class)->eliminarAbono($credito, $recibido);
        $this->assertDatabaseMissing('pagos', ['id' => $recibido->id]);
        $this->assertDatabaseMissing('movimientos_caja', ['pago_id' => $recibido->id]);
        $this->assertSame(200.0, (float) $credito->fresh()->saldo_pendiente);
    }

    public function test_deleting_a_payment_reactivates_a_paid_off_credit_with_a_pending_balance(): void
    {
        $credito = $this->credito();
        $credito->update([
            'monto_otorgado' => 300,
            'total' => 300,
            'saldo_pendiente' => 300,
            'plazos' => 3,
        ]);
        $service = app(PagoService::class);

        $service->registrar($credito, [
            'fecha' => '2026-09-05', 'hora' => '08:00:00', 'monto' => 100,
        ]);
        $service->registrar($credito, [
            'fecha' => '2026-09-12', 'hora' => '08:00:00', 'monto' => 100,
        ]);
        $ultimo = $service->registrar($credito, [
            'fecha' => '2026-09-19', 'hora' => '08:00:00', 'monto' => 100,
        ])['pago'];

        $this->assertSame('Finalizado', $credito->fresh()->estado);
        $this->assertSame(0.0, (float) $credito->fresh()->saldo_pendiente);

        $service->eliminarAbono($credito, $ultimo);

        $this->assertSame('Activo', $credito->fresh()->estado);
        $this->assertSame(100.0, (float) $credito->fresh()->saldo_pendiente);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('receivedAmounts')]
    public function test_deleting_a_received_payment_adjusts_the_cut_and_preserves_other_income(float $recibido): void
    {
        $credito = $this->credito();
        $service = app(PagoService::class);
        $primero = $service->registrar($credito, [
            'fecha' => '2026-09-05', 'hora' => '09:00:00', 'monto' => 100,
        ])['pago'];
        $erroneo = $service->registrar($credito, [
            'fecha' => '2026-09-05', 'hora' => '10:00:00', 'monto' => 100,
        ])['pago'];
        $recepcion = app(ReportService::class)->registrarRecepcionAsesor('2026-09-05', $credito->id_asesor, $recibido);
        $posterior = app(FlujoCajaService::class)->registrar([
            'fecha' => '2026-09-06', 'motivo' => 'Otro ingreso', 'tipo' => 'Ingreso', 'monto' => 25,
        ]);
        $horaCorte = $recepcion->updated_at->toDateTimeString();
        Carbon::setTestNow('2026-09-05 12:00:00');

        $service->eliminarAbono($credito, $erroneo);

        $this->assertDatabaseMissing('pagos', ['id' => $erroneo->id]);
        $this->assertDatabaseMissing('movimientos_caja', ['pago_id' => $erroneo->id]);
        $this->assertSame(100.0, (float) MovimientoCaja::where('pago_id', $primero->id)->value('monto'));
        $this->assertSame(100.0, (float) $credito->fresh()->saldo_pendiente);
        $this->assertSame(100.0, (float) $recepcion->fresh()->monto_recibido);
        $this->assertSame(100.0, (float) $recepcion->fresh()->monto_esperado);
        $this->assertSame($horaCorte, $recepcion->fresh()->updated_at->toDateTimeString());
        $this->assertSame(125.0, (float) $posterior->fresh()->saldo_resultante);
    }

    public static function receivedAmounts(): array
    {
        return ['partial' => [160.0], 'full' => [200.0]];
    }

    public function test_individual_receipt_preserves_selected_payment_and_completes_partial_without_duplicates(): void
    {
        $credito = $this->credito();
        $pagos = app(PagoService::class);
        $primero = $pagos->registrar($credito, [
            'fecha' => '2026-09-05', 'hora' => '09:00:00', 'monto' => 100,
        ])['pago'];
        $segundo = $pagos->registrar($credito, [
            'fecha' => '2026-09-05', 'hora' => '10:00:00', 'monto' => 100,
        ])['pago'];
        $reportes = app(ReportService::class);
        $reportes->recibirAbonoDiario($segundo);
        $this->assertDatabaseMissing('movimientos_caja', ['pago_id' => $primero->id]);
        $this->assertDatabaseHas('movimientos_caja', ['pago_id' => $segundo->id, 'monto' => 100]);
        $this->assertDatabaseHas('recepciones_asesor', ['id_asesor' => $credito->id_asesor, 'monto_recibido' => 100]);

        $reportes->registrarRecepcionAsesor('2026-09-05', $credito->id_asesor, 50, agregar: true);
        $this->assertDatabaseHas('movimientos_caja', ['pago_id' => $primero->id, 'monto' => 50]);
        $this->assertDatabaseHas('movimientos_caja', ['pago_id' => $segundo->id, 'monto' => 100]);

        $reportes->recibirAbonoDiario($primero);
        $reportes->recibirAbonoDiario($primero);
        $this->assertSame(2, MovimientoCaja::count());
        $this->assertSame(200.0, (float) MovimientoCaja::sum('monto'));
        $this->assertDatabaseHas('recepciones_asesor', ['id_asesor' => $credito->id_asesor, 'monto_recibido' => 200]);

        $reportes->registrarRecepcionAsesor('2026-09-05', $credito->id_asesor, 75);
        $this->assertSame(75.0, (float) MovimientoCaja::sum('monto'));
        $this->assertDatabaseHas('movimientos_caja', ['pago_id' => $primero->id, 'monto' => 75]);
        $this->assertDatabaseMissing('movimientos_caja', ['pago_id' => $segundo->id]);
    }

    public function test_individual_receipt_does_not_count_unallocated_cash_twice(): void
    {
        $credito = $this->credito();
        $reportes = app(ReportService::class);
        $reportes->registrarRecepcionAsesor('2026-09-05', $credito->id_asesor, 100);
        $pago = app(PagoService::class)->registrar($credito, [
            'fecha' => '2026-09-05', 'hora' => '09:00:00', 'monto' => 100,
        ])['pago'];
        $reportes->recibirAbonoDiario($pago);
        $this->assertSame(100.0, (float) MovimientoCaja::sum('monto'));
        $this->assertDatabaseHas('recepciones_asesor', ['id_asesor' => $credito->id_asesor, 'monto_recibido' => 100]);
    }

    public function test_individual_receipt_rejects_fines(): void
    {
        $credito = $this->credito();
        $pago = Pago::create([
            'num_prog' => $credito->num_prog, 'fecha' => '2026-09-05',
            'hora' => '09:00:00', 'monto' => 20, 'tipo' => 'Multa',
        ]);
        $this->expectException(\InvalidArgumentException::class);
        app(ReportService::class)->recibirAbonoDiario($pago);
    }

    private function credito(): Credito
    {
        $asesor = Asesor::create(['nombre_asesor' => 'Gestora']);
        $cliente = Cliente::create(['id_cliente' => 'CLI-001', 'nombre_completo' => 'Cliente de prueba']);

        return Credito::create([
            'id_cliente' => $cliente->id_cliente, 'id_asesor' => $asesor->id,
            'fecha_otorgacion' => '2026-08-29', 'fecha_primer_pago' => '2026-09-05', 'ciclo' => 1,
            'monto_otorgado' => 200, 'interes' => 0, 'total' => 200, 'saldo_pendiente' => 200,
            'plazos' => 2, 'valor_ficha' => 100, 'dias_pago' => 'SABADO', 'tipo_credito' => 'Individual', 'estado' => 'Activo',
        ]);
    }

    private function row(Credito $credito): array
    {
        return [
            'row_number' => 2, 'folio' => $credito->num_prog, 'cliente_grupo' => 'Cliente de prueba', 'gestor' => 'Gestora', 'categoria' => 'Del día',
            'cuota' => 1, 'fecha_cuota' => '2026-09-05', 'importe_esperado' => 100, 'fecha_pago' => '2026-09-05',
            'referencia_ruta' => PagosRutaImportService::referenciaRuta($credito->num_prog, 1, '2026-09-05'),
            'pago_realizado' => 'SI', 'metodo_pago' => 'Efectivo', 'notas' => null,
        ];
    }

    private function createSchema(): void
    {
        foreach (['movimientos_caja', 'recepciones_asesor', 'pagos', 'refinanciamientos', 'creditos', 'clientes', 'grupos', 'asesores'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('asesores', fn (Blueprint $t) => tap($t, fn ($t) => [$t->id(), $t->string('nombre_asesor'), $t->timestamps()]));
        Schema::create('clientes', fn (Blueprint $t) => tap($t, fn ($t) => [$t->string('id_cliente')->primary(), $t->string('nombre_completo'), $t->timestamps()]));
        Schema::create('grupos', fn (Blueprint $t) => tap($t, fn ($t) => [$t->id(), $t->string('nombre_grupo'), $t->timestamps()]));
        Schema::create('creditos', function (Blueprint $t) {
            $t->id('num_prog');
            $t->string('id_cliente')->nullable();
            $t->unsignedBigInteger('id_grupo')->nullable();
            $t->unsignedBigInteger('id_asesor')->nullable();
            $t->date('fecha_otorgacion');
            $t->date('fecha_primer_pago')->nullable();
            $t->integer('ciclo');
            $t->integer('ciclo_inicio_mora')->nullable();
            $t->decimal('monto_otorgado', 12, 2);
            $t->decimal('interes', 12, 2);
            $t->decimal('total', 12, 2);
            $t->decimal('saldo_pendiente', 12, 2)->nullable();
            $t->integer('plazos');
            $t->decimal('valor_ficha', 12, 2);
            $t->string('dias_pago');
            $t->string('tipo_credito');
            $t->string('estado');
            $t->integer('dias_mora_cache')->default(0);
            $t->timestamps();
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('num_prog');
            $t->decimal('monto', 12, 2);
            $t->decimal('ahorro_personal_monto', 12, 2)->default(0);
            $t->date('fecha');
            $t->time('hora')->nullable();
            $t->string('metodo_pago')->nullable();
            $t->string('tipo');
            $t->text('notas')->nullable();
            $t->string('referencia_importacion')->nullable()->unique();
            $t->unsignedBigInteger('registrado_por')->nullable();
            $t->timestamps();
        });
        Schema::create('refinanciamientos', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('num_prog_anterior');
            $t->unsignedBigInteger('num_prog_nuevo')->nullable();
            $t->date('fecha_efectiva')->nullable();
            $t->timestamps();
        });
        Schema::create('movimientos_caja', function (Blueprint $t) {
            $t->id();
            $t->date('fecha');
            $t->unsignedBigInteger('id_asesor')->nullable();
            $t->text('motivo');
            $t->string('tipo');
            $t->decimal('monto', 14, 2);
            $t->decimal('saldo_resultante', 14, 2)->nullable();
            $t->string('categoria')->nullable();
            $t->string('cuenta')->nullable();
            $t->unsignedBigInteger('num_prog')->nullable();
            $t->unsignedBigInteger('pago_id')->nullable();
            $t->string('referencia')->nullable();
            $t->unsignedBigInteger('registrado_por')->nullable();
            $t->timestamps();
        });
        Schema::create('recepciones_asesor', function (Blueprint $t) {
            $t->id();
            $t->date('fecha');
            $t->unsignedBigInteger('id_asesor');
            $t->decimal('monto_esperado', 14, 2)->default(0);
            $t->decimal('monto_recibido', 14, 2)->default(0);
            $t->text('notas')->nullable();
            $t->unsignedBigInteger('registrado_por')->nullable();
            $t->timestamps();
            $t->unique(['fecha', 'id_asesor']);
        });
    }
}
