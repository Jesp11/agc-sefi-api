<?php

namespace Tests\Feature;

use App\Models\Asesor;
use App\Models\Cliente;
use App\Models\Credito;
use App\Models\Grupo;
use App\Models\Pago;
use App\Models\Refinanciamiento;
use App\Http\Controllers\CarteraController;
use App\Services\CarteraService;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PagosAtrasadosReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        Carbon::setTestNow('2026-09-04 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_reports_partial_installments_applies_only_abonos_and_groups_totals_by_advisor(): void
    {
        $advisorA = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora']);
        $advisorB = Asesor::create(['id_asesor' => 'ASE-002', 'nombre_asesor' => 'Beto Gestor']);
        $clienteA = $this->cliente('CLI-001', 'Cliente Ana');
        $clienteB = $this->cliente('CLI-002', 'Cliente Beto');

        $partiallyPaid = $this->credito($advisorA, $clienteA, 'Activo', '2026-08-01', 4);
        Pago::create(['num_prog' => $partiallyPaid->num_prog, 'monto' => 100, 'fecha' => '2026-08-03', 'hora' => '09:00:00', 'tipo' => 'Abono']);
        Pago::create(['num_prog' => $partiallyPaid->num_prog, 'monto' => 50, 'fecha' => '2026-08-15', 'hora' => '09:00:00', 'tipo' => 'Abono']);
        Pago::create(['num_prog' => $partiallyPaid->num_prog, 'monto' => 500, 'fecha' => '2026-08-16', 'hora' => '09:00:00', 'tipo' => 'Multa']);

        $fullyPaid = $this->credito($advisorA, $clienteA, 'Activo', '2026-08-01', 2);
        Pago::create(['num_prog' => $fullyPaid->num_prog, 'monto' => 200, 'fecha' => '2026-08-02', 'hora' => '09:00:00', 'tipo' => 'Abono']);

        $otherAdvisor = $this->credito($advisorB, $clienteB, 'Activo', '2026-08-01', 2);
        Pago::create(['num_prog' => $otherAdvisor->num_prog, 'monto' => 100, 'fecha' => '2026-08-02', 'hora' => '09:00:00', 'tipo' => 'Abono']);

        $this->credito($advisorA, $clienteA, 'EnMora', '2026-08-01', 2);
        $this->credito($advisorA, $clienteA, 'Finalizado', '2026-08-01', 2);
        $this->credito($advisorA, $clienteA, 'CerradoSinRenovacion', '2026-08-01', 2);

        $report = app(ReportService::class)->pagosAtrasados('2026-08-08', '2026-08-22');

        $this->assertSame(2, $report['resumen']['creditos']);
        $this->assertSame(4, $report['resumen']['cuotas_atrasadas']);
        $this->assertSame(350.0, $report['resumen']['importe_pendiente']);
        $this->assertSame(['2026-08-08', '2026-08-15', '2026-08-22'], array_column($report['por_asesor'][0]['cuotas'], 'fecha_vencimiento'));
        $this->assertSame([50.0, 100.0, 100.0], array_column($report['por_asesor'][0]['cuotas'], 'importe_pendiente'));
        $this->assertSame(1, $report['por_asesor'][0]['resumen']['creditos']);
        $this->assertSame(250.0, $report['por_asesor'][0]['resumen']['importe_pendiente']);
    }

    public function test_filters_dates_and_advisor_scope(): void
    {
        $advisorA = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora']);
        $advisorB = Asesor::create(['id_asesor' => 'ASE-002', 'nombre_asesor' => 'Beto Gestor']);
        $clienteA = $this->cliente('CLI-001', 'Cliente Ana');
        $clienteB = $this->cliente('CLI-002', 'Cliente Beto');
        $this->credito($advisorA, $clienteA, 'Activo', '2026-08-01', 3);
        $this->credito($advisorB, $clienteB, 'Activo', '2026-08-01', 3);

        $report = app(ReportService::class)->pagosAtrasados('2026-08-08', '2026-08-08', $advisorA->id);

        $this->assertSame(1, $report['resumen']['creditos']);
        $this->assertSame(1, $report['resumen']['cuotas_atrasadas']);
        $this->assertSame($advisorA->id, $report['por_asesor'][0]['id_asesor']);
        $this->assertSame('2026-08-08', $report['por_asesor'][0]['cuotas'][0]['fecha_vencimiento']);

        $empty = app(ReportService::class)->pagosAtrasados('2026-08-29', '2026-08-31', $advisorA->id);
        $this->assertSame(0, $empty['resumen']['cuotas_atrasadas']);
        $this->assertSame([], $empty['por_asesor']);
    }

    public function test_cartera_marks_paid_and_pending_installments_separately(): void
    {
        $advisor = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora']);
        $cliente = $this->cliente('CLI-001', 'Cliente Ana');
        $credito = $this->credito($advisor, $cliente, 'Activo', '2026-08-01', 16);

        for ($i = 0; $i < 14; $i++) {
            Pago::create([
                'num_prog' => $credito->num_prog,
                'monto' => 100,
                'fecha' => Carbon::parse('2026-08-01')->addWeeks($i)->toDateString(),
                'hora' => '09:00:00',
                'tipo' => 'Abono',
            ]);
        }

        $reporte = app(ReportService::class)->cartera('general');
        $fila = $reporte['creditos']->first();
        $estados = $fila['estado_pagos_programados'];

        $this->assertSame(2, $fila['semanas_restantes']);
        $this->assertSame(array_fill(0, 14, 'Pagado'), array_column(array_slice($estados, 0, 14), 'estado'));
        $this->assertSame(['Pendiente', 'Pendiente'], array_column(array_slice($estados, 14, 2), 'estado'));
    }

    public function test_daily_collection_marks_a_credit_with_an_abono_on_the_selected_date(): void
    {
        $advisor = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora']);
        $cliente = $this->cliente('CLI-001', 'Cliente Ana');
        $credito = $this->credito($advisor, $cliente, 'Activo', '2026-08-01', 4);
        $atrasado = $this->credito($advisor, $cliente, 'Activo', '2026-08-01', 4);
        $atrasado->update(['dias_pago' => 'LUNES']);

        Pago::create([
            'num_prog' => $credito->num_prog,
            'monto' => 100,
            'fecha' => '2026-08-08',
            'hora' => '09:00:00',
            'tipo' => 'Abono',
        ]);

        $cobro = collect(app(CarteraService::class)->cobrosDelDia('2026-08-08')['cobros'])
            ->firstWhere('num_prog', $credito->num_prog);

        $this->assertNotNull($cobro);
        $this->assertTrue($cobro['pagado_hoy']);
        $this->assertSame(100.0, app(CarteraService::class)->cobrosDelDia('2026-08-08')['monto_a_cobrar']);
    }

    public function test_closed_portfolio_excludes_a_credit_replaced_by_an_active_renewal(): void
    {
        $advisor = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora']);
        $cliente = $this->cliente('CLI-001', 'Cliente Ana');
        $renovado = $this->credito($advisor, $cliente, 'Finalizado', '2026-08-01', 4);
        $vigente = $this->credito($advisor, $cliente, 'Activo', '2026-09-01', 4);
        $cerradoConHijo = $this->credito($advisor, $cliente, 'Finalizado', '2026-08-01', 4);
        $hijoVigente = $this->credito($advisor, $cliente, 'Activo', '2026-09-01', 4);
        $clienteSinRenovar = $this->cliente('CLI-002', 'Cliente Sin Renovar');
        $cerradoSinRenovar = $this->credito($advisor, $clienteSinRenovar, 'CerradoSinRenovacion', '2026-08-01', 4);
        $hijoVigente->update(['credito_padre_id' => $cerradoConHijo->num_prog]);

        Refinanciamiento::create([
            'num_prog_anterior' => $renovado->num_prog,
            'num_prog_nuevo' => $vigente->num_prog,
            'saldo_anterior' => 100,
            'deduccion' => 100,
            'monto_neto' => 300,
        ]);

        $response = app(CarteraController::class)->cerrados(new Request(['tipo' => 'individual']));
        $folios = array_column($response->getData(true)['data'], 'num_prog');

        $this->assertSame([$cerradoSinRenovar->num_prog], $folios);
    }

    public function test_closed_portfolio_excludes_group_with_active_credit_without_formal_renewal(): void
    {
        $advisor = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora']);
        $grupoActivo = Grupo::create(['nombre_grupo' => 'Grupo Las Rosas']);
        $grupoCerrado = Grupo::create(['nombre_grupo' => 'Grupo Los Pinos']);

        // Grupo con crédito finalizado pero con otro crédito activo no ligado por refinanciamiento
        $creditoAnteriorGrupoActivo = $this->creditoGrupal($advisor, $grupoActivo, 'Finalizado', '2026-08-01', 4);
        $creditoNuevoGrupoActivo = $this->creditoGrupal($advisor, $grupoActivo, 'Activo', '2026-09-01', 4);

        // Grupo sin crédito activo (realmente cerrado)
        $creditoGrupoCerrado = $this->creditoGrupal($advisor, $grupoCerrado, 'CerradoSinRenovacion', '2026-08-01', 4);

        $response = app(CarteraController::class)->cerrados(new Request(['tipo' => 'grupal']));
        $folios = array_column($response->getData(true)['data'], 'num_prog');

        $this->assertSame([$creditoGrupoCerrado->num_prog], $folios);
    }

    public function test_closed_portfolio_excludes_individual_client_with_active_credit_without_formal_renewal(): void
    {
        $advisor = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora']);
        $clienteActivo = $this->cliente('CLI-010', 'Cliente Con Nuevo Credito');
        $clienteCerrado = $this->cliente('CLI-011', 'Cliente Sin Nuevo Credito');

        // Cliente con crédito cerrado anterior pero con nuevo crédito activo no formalmente renovado
        $creditoAnterior = $this->credito($advisor, $clienteActivo, 'Finalizado', '2026-08-01', 4);
        $creditoNuevo = $this->credito($advisor, $clienteActivo, 'Activo', '2026-09-01', 4);

        // Cliente cerrado real
        $creditoRealCerrado = $this->credito($advisor, $clienteCerrado, 'CerradoSinRenovacion', '2026-08-01', 4);

        $response = app(CarteraController::class)->cerrados(new Request(['tipo' => 'individual']));
        $folios = array_column($response->getData(true)['data'], 'num_prog');

        $this->assertSame([$creditoRealCerrado->num_prog], $folios);
    }

    private function cliente(string $id, string $nombre): Cliente
    {
        return Cliente::create(['id_cliente' => $id, 'nombre_completo' => $nombre]);
    }

    private function credito(Asesor $asesor, Cliente $cliente, string $estado, string $fechaPrimerPago, int $plazos): Credito
    {
        return Credito::create([
            'id_cliente' => $cliente->id_cliente,
            'id_asesor' => $asesor->id,
            'fecha_otorgacion' => '2026-07-25',
            'fecha_primer_pago' => $fechaPrimerPago,
            'ciclo' => 1,
            'monto_otorgado' => 1000,
            'interes' => 0,
            'total' => $plazos * 100,
            'saldo_pendiente' => $plazos * 100,
            'plazos' => $plazos,
            'valor_ficha' => 100,
            'dias_pago' => 'SABADO',
            'tipo_credito' => 'Individual',
            'estado' => $estado,
        ]);
    }

    private function creditoGrupal(Asesor $asesor, Grupo $grupo, string $estado, string $fechaPrimerPago, int $plazos): Credito
    {
        return Credito::create([
            'id_grupo' => $grupo->id,
            'id_asesor' => $asesor->id,
            'fecha_otorgacion' => '2026-07-25',
            'fecha_primer_pago' => $fechaPrimerPago,
            'ciclo' => 1,
            'monto_otorgado' => 1000,
            'interes' => 0,
            'total' => $plazos * 100,
            'saldo_pendiente' => $plazos * 100,
            'plazos' => $plazos,
            'valor_ficha' => 100,
            'dias_pago' => 'SABADO',
            'tipo_credito' => 'Grupal',
            'estado' => $estado,
        ]);
    }

    private function createSchema(): void
    {
        foreach (['pagos', 'refinanciamientos', 'creditos', 'grupos', 'clientes', 'asesores'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('asesores', function (Blueprint $table) { $table->id(); $table->string('id_asesor')->nullable(); $table->string('nombre_asesor'); $table->timestamps(); });
        Schema::create('clientes', function (Blueprint $table) { $table->string('id_cliente')->primary(); $table->string('nombre_completo'); $table->timestamps(); });
        Schema::create('grupos', function (Blueprint $table) { $table->id(); $table->string('nombre_grupo'); $table->timestamps(); });
        Schema::create('creditos', function (Blueprint $table) {
            $table->id('num_prog'); $table->string('id_cliente')->nullable(); $table->unsignedBigInteger('id_grupo')->nullable(); $table->unsignedBigInteger('id_asesor');
            $table->date('fecha_otorgacion'); $table->date('fecha_primer_pago')->nullable(); $table->integer('ciclo'); $table->decimal('monto_otorgado', 12, 2);
            $table->decimal('interes', 12, 2); $table->decimal('total', 12, 2); $table->decimal('saldo_pendiente', 12, 2)->nullable(); $table->integer('plazos');
            $table->decimal('valor_ficha', 12, 2); $table->string('dias_pago'); $table->string('tipo_credito'); $table->string('estado'); $table->timestamps();
            $table->unsignedBigInteger('credito_padre_id')->nullable();
        });
        Schema::create('refinanciamientos', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('num_prog_anterior'); $table->unsignedBigInteger('num_prog_nuevo');
            $table->decimal('saldo_anterior', 12, 2); $table->decimal('deduccion', 12, 2); $table->decimal('monto_neto', 12, 2);
            $table->date('fecha_efectiva')->nullable(); $table->timestamps();
        });
        Schema::create('pagos', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('num_prog'); $table->decimal('monto', 12, 2); $table->date('fecha'); $table->time('hora')->nullable(); $table->string('tipo'); $table->timestamps();
        });
    }
}
