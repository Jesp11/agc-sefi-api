<?php

namespace Tests\Feature;

use App\Models\Asesor;
use App\Models\Cliente;
use App\Models\Credito;
use App\Models\Grupo;
use App\Models\Pago;
use App\Models\RecepcionAsesor;
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

        $pago = Pago::create([
            'num_prog' => $credito->num_prog,
            'monto' => 100,
            'fecha' => '2026-08-08',
            'hora' => '09:00:00',
            'tipo' => 'Abono',
        ]);

        $reporte = app(CarteraService::class)->cobrosDelDia('2026-08-08');
        $cobro = collect($reporte['cobros'])
            ->firstWhere('num_prog', $credito->num_prog);

        $this->assertNotNull($cobro);
        $this->assertTrue($cobro['pagado_hoy']);
        $this->assertSame(100.0, $reporte['monto_a_cobrar']);
        $this->assertSame([$pago->id], collect($reporte['pagos'])->pluck('id')->all());
    }

    public function test_daily_collection_accumulates_all_payments_for_an_overdue_client(): void
    {
        $advisor = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora']);
        $cliente = $this->cliente('CLI-001', 'Cliente atrasado');
        $credito = $this->credito($advisor, $cliente, 'Activo', '2026-08-01', 4);
        $credito->update(['dias_pago' => 'LUNES']);

        Pago::create(['num_prog' => $credito->num_prog, 'monto' => 40, 'fecha' => '2026-08-08', 'hora' => '09:00:00', 'tipo' => 'Abono']);
        Pago::create(['num_prog' => $credito->num_prog, 'monto' => 60, 'fecha' => '2026-08-08', 'hora' => '10:00:00', 'tipo' => 'Abono']);

        $cobro = collect(app(CarteraService::class)->cobrosDelDia('2026-08-08')['cobros'])
            ->firstWhere('num_prog', $credito->num_prog);

        $this->assertSame('atrasado', $cobro['categoria']);
        $this->assertSame(100.0, $cobro['monto_abonado_hoy']);
        $this->assertTrue($cobro['pagado_hoy']);
    }

    public function test_daily_route_keeps_full_installment_despite_prior_credit_balance(): void
    {
        $advisor = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora']);
        $cliente = $this->cliente('CLI-001', 'Cliente Ana');
        $credito = $this->credito($advisor, $cliente, 'Activo', '2026-08-01', 3);
        Pago::create(['num_prog' => $credito->num_prog, 'monto' => 25, 'fecha' => '2026-08-01', 'hora' => '09:00:00', 'tipo' => 'Abono']);

        $cobro = collect(app(CarteraService::class)->cobrosDelDia('2026-08-08')['cobros'])
            ->firstWhere('num_prog', $credito->num_prog);

        $this->assertNotNull($cobro);
        $this->assertSame('del_dia', $cobro['categoria']);
        $this->assertSame(100.0, $cobro['monto_a_cobrar']);
        $this->assertSame(100.0, $cobro['pendientes'][0]['monto']);
    }

    public function test_daily_report_includes_mora_payments_in_advisor_total_and_mora_section(): void
    {
        $advisor = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora']);
        $cliente = $this->cliente('CLI-001', 'Cliente Ana');
        $credito = $this->credito($advisor, $cliente, 'EnMora', '2026-08-01', 2);

        Pago::create([
            'num_prog' => $credito->num_prog,
            'monto' => 75,
            'fecha' => '2026-08-08',
            'hora' => '09:00:00',
            'tipo' => 'Abono',
        ]);

        $reporte = app(ReportService::class)->reporteDiario('2026-08-08');
        $asesor = collect($reporte['por_asesor'])->firstWhere('id_asesor', $advisor->id);

        $this->assertSame(75.0, $reporte['total_abonos']);
        $this->assertNotNull($asesor);
        $this->assertSame(75.0, $asesor['total_cobrado']);
        $this->assertCount(1, $asesor['creditos_mora']);
        $this->assertTrue($asesor['creditos_mora'][0]['pagado_hoy']);
    }

    public function test_daily_report_treats_an_amount_below_the_next_full_installment_as_extra(): void
    {
        $advisor = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora']);
        $clienteQuePaga = $this->cliente('CLI-001', 'Cliente con saldo a favor');
        $clientePendiente = $this->cliente('CLI-002', 'Cliente pendiente');
        $creditoQuePaga = $this->credito($advisor, $clienteQuePaga, 'Activo', '2026-09-05', 2);
        $creditoPendiente = $this->credito($advisor, $clientePendiente, 'Activo', '2026-09-05', 2);
        $creditoQuePaga->update(['total' => 1180, 'saldo_pendiente' => 1180, 'valor_ficha' => 590]);
        $creditoPendiente->update(['total' => 1200, 'saldo_pendiente' => 1200, 'valor_ficha' => 600]);

        $pago = Pago::create([
            'num_prog' => $creditoQuePaga->num_prog,
            'monto' => 600,
            'fecha' => '2026-09-05',
            'hora' => '09:00:00',
            'tipo' => 'Abono',
        ]);

        $reporte = app(ReportService::class)->reporteDiario('2026-09-05');
        $asesor = collect($reporte['por_asesor'])->firstWhere('id_asesor', $advisor->id);
        $pagoReportado = collect($reporte['pagos'])->firstWhere('id', $pago->id);

        $this->assertSame(600.0, $reporte['total_pendiente_cobro']);
        $this->assertSame(600.0, $asesor['monto_pendiente_cobro']);
        $this->assertSame(0.0, (float) $pagoReportado->monto_adelantado_hoy);
        $this->assertSame(10.0, (float) $pagoReportado->monto_extra_hoy);
    }

    public function test_daily_report_applies_a_scheduled_payment_to_todays_route_before_arrears(): void
    {
        $advisor = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora']);
        $cliente = $this->cliente('CLI-001', 'Cliente con cuota atrasada');
        $credito = $this->credito($advisor, $cliente, 'Activo', '2026-09-05', 3);
        $pago = Pago::create([
            'num_prog' => $credito->num_prog,
            'monto' => 100,
            'fecha' => '2026-09-12',
            'hora' => '09:00:00',
            'tipo' => 'Abono',
        ]);

        $reporte = app(ReportService::class)->reporteDiario('2026-09-12');
        $asesor = collect($reporte['por_asesor'])->firstWhere('id_asesor', $advisor->id);
        $pagoReportado = collect($reporte['pagos'])->firstWhere('id', $pago->id);

        $this->assertSame(100.0, $reporte['total_programado_dia']);
        $this->assertSame(0.0, $reporte['total_pendiente_cobro']);
        $this->assertSame(0.0, $asesor['monto_pendiente_cobro']);
        $this->assertSame(0.0, (float) $pagoReportado->monto_atrasado_hoy);
        $this->assertSame(100.0, (float) $pagoReportado->monto_del_dia_hoy);
    }

    public function test_credit_balance_in_favor_is_calculated_independently_per_credit(): void
    {
        $advisor = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora']);
        $cliente = $this->cliente('CLI-001', 'Cliente con varios créditos');
        $creditoConSaldo = $this->credito($advisor, $cliente, 'Activo', '2026-09-05', 4);
        $creditoSinSaldo = $this->credito($advisor, $cliente, 'Activo', '2026-09-05', 4);

        Pago::create(['num_prog' => $creditoConSaldo->num_prog, 'monto' => 250, 'fecha' => '2026-09-05', 'tipo' => 'Abono']);
        Pago::create(['num_prog' => $creditoSinSaldo->num_prog, 'monto' => 100, 'fecha' => '2026-09-05', 'tipo' => 'Abono']);

        $this->assertSame(50.0, app(CarteraService::class)->saldoFavorCredito($creditoConSaldo));
        $this->assertSame(0.0, app(CarteraService::class)->saldoFavorCredito($creditoSinSaldo));
    }

    public function test_daily_report_exposes_actual_received_and_pending_amounts_per_payment(): void
    {
        $advisor = Asesor::create(['nombre_asesor' => 'Gestora']);
        $credito = $this->credito($advisor, $this->cliente('CLI-001', 'Cliente'), 'Activo', '2026-09-05', 4);
        $pagos = collect([0, 40, 100])->map(function ($recibido) use ($credito, $advisor) {
            $pago = Pago::create([
                'num_prog' => $credito->num_prog, 'monto' => 100,
                'fecha' => '2026-09-05', 'hora' => '09:00:00', 'tipo' => 'Abono',
            ]);
            if ($recibido > 0) {
                \App\Models\MovimientoCaja::create([
                    'pago_id' => $pago->id, 'num_prog' => $credito->num_prog, 'id_asesor' => $advisor->id,
                    'fecha' => '2026-09-05', 'tipo' => 'Ingreso', 'monto' => $recibido,
                    'motivo' => 'Recepción', 'categoria' => 'CobroCartera',
                ]);
            }
            return $pago;
        });
        $reporte = app(ReportService::class)->reporteDiario('2026-09-05');
        foreach ([0, 40, 100] as $i => $recibido) {
            $pago = collect($reporte['pagos'])->firstWhere('id', $pagos[$i]->id);
            $this->assertSame((float) $recibido, $pago->monto_recibido_caja);
            $this->assertSame((float) (100 - $recibido), $pago->monto_pendiente_caja);
        }
    }

    public function test_daily_report_receivable_only_includes_the_advisors_scheduled_route(): void
    {
        $advisor = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora']);
        $cliente = $this->cliente('CLI-001', 'Cliente Ana');
        $ruta = $this->credito($advisor, $cliente, 'Activo', '2026-09-05', 4);
        $adelantado = $this->credito($advisor, $cliente, 'Activo', '2026-09-12', 4);
        $mora = $this->credito($advisor, $cliente, 'EnMora', '2026-08-01', 2);

        foreach ([$ruta, $adelantado, $mora] as $credito) {
            Pago::create([
                'num_prog' => $credito->num_prog,
                'monto' => 150,
                'fecha' => '2026-09-05',
                'hora' => '09:00:00',
                'tipo' => 'Abono',
            ]);
        }

        $reporte = app(ReportService::class)->reporteDiario('2026-09-05');
        $asesor = collect($reporte['por_asesor'])->firstWhere('id_asesor', $advisor->id);

        $this->assertSame(450.0, $asesor['total_cobrado']);
        $this->assertSame(100.0, $asesor['prog_del_dia']);
        $this->assertSame(100.0, $asesor['a_recibir']);
        $this->assertSame(100.0, $asesor['a_recibir_bruto']);
        $this->assertSame(100.0, $reporte['total_a_recibir']);
    }

    public function test_daily_report_lists_an_early_payment_once_and_omits_its_future_installment(): void
    {
        $advisor = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora']);
        $cliente = $this->cliente('CLI-001', 'Cliente anticipado');
        $credito = $this->credito($advisor, $cliente, 'Activo', '2026-09-12', 2);
        $pago = Pago::create([
            'num_prog' => $credito->num_prog,
            'monto' => 100,
            'fecha' => '2026-09-05',
            'hora' => '09:00:00',
            'tipo' => 'Abono',
        ]);

        $reportePago = app(ReportService::class)->reporteDiario('2026-09-05');
        $asesorPago = collect($reportePago['por_asesor'])->firstWhere('id_asesor', $advisor->id);
        $reporteVencimiento = app(ReportService::class)->reporteDiario('2026-09-12');
        $asesorVencimiento = collect($reporteVencimiento['por_asesor'])->firstWhere('id_asesor', $advisor->id);

        $this->assertSame([$pago->id], collect($reportePago['pagos_anticipados'])->pluck('id')->all());
        $this->assertSame([$pago->id], collect($asesorPago['pagos_anticipados'])->pluck('id')->all());
        $this->assertSame(100.0, $asesorPago['total_cobrado']);
        $this->assertSame(0.0, $asesorPago['a_recibir']);
        $this->assertNull($asesorVencimiento);
        $this->assertSame([], $reporteVencimiento['cobros_programados']->all());
    }

    public function test_daily_report_includes_payment_number_for_ticket_reprints(): void
    {
        $advisor = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora']);
        $cliente = $this->cliente('CLI-001', 'Cliente Ana');
        $credito = $this->credito($advisor, $cliente, 'Activo', '2026-08-01', 4);

        Pago::create([
            'num_prog' => $credito->num_prog,
            'monto' => 100,
            'fecha' => '2026-08-08',
            'hora' => '09:00:00',
            'tipo' => 'Abono',
        ]);
        $segundoPago = Pago::create([
            'num_prog' => $credito->num_prog,
            'monto' => 100,
            'fecha' => '2026-08-15',
            'hora' => '09:00:00',
            'tipo' => 'Abono',
        ]);
        Pago::create([
            'num_prog' => $credito->num_prog,
            'monto' => 100,
            'fecha' => '2026-08-22',
            'hora' => '09:00:00',
            'tipo' => 'Abono',
        ]);

        $reporte = app(ReportService::class)->reporteDiario('2026-08-15');
        $pagoTicket = collect($reporte['pagos'])->firstWhere('id', $segundoPago->id);

        $this->assertSame(2, $pagoTicket->num_pago);
        $this->assertSame(4, $pagoTicket->total_pagos);
    }

    public function test_daily_report_moves_the_payment_after_settling_arrears_to_advance_payments(): void
    {
        $advisor = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora']);
        $cliente = $this->cliente('CLI-001', 'Cliente con atrasos');
        $credito = $this->credito($advisor, $cliente, 'Activo', '2026-08-01', 4);

        $primero = Pago::create(['num_prog' => $credito->num_prog, 'monto' => 100, 'fecha' => '2026-08-14', 'hora' => '09:00:00', 'tipo' => 'Abono']);
        $segundo = Pago::create(['num_prog' => $credito->num_prog, 'monto' => 100, 'fecha' => '2026-08-14', 'hora' => '10:00:00', 'tipo' => 'Abono']);
        $adelantado = Pago::create(['num_prog' => $credito->num_prog, 'monto' => 100, 'fecha' => '2026-08-14', 'hora' => '11:00:00', 'tipo' => 'Abono']);

        $reporte = app(ReportService::class)->reporteDiario('2026-08-14');
        $asesor = collect($reporte['por_asesor'])->firstWhere('id_asesor', $advisor->id);
        $cobroAtrasado = $reporte['cobros_programados']->firstWhere('num_prog', $credito->num_prog);

        $this->assertSame([$adelantado->id], collect($reporte['pagos_anticipados'])->pluck('id')->all());
        $this->assertSame([$adelantado->id], collect($asesor['pagos_anticipados'])->pluck('id')->all());
        $this->assertSame(200.0, $cobroAtrasado['monto_abonado_atrasado_hoy']);
        $this->assertSame(100.0, (float) collect($reporte['pagos'])->firstWhere('id', $adelantado->id)->monto_adelantado_hoy);
        $this->assertSame(100.0, (float) collect($reporte['pagos'])->firstWhere('id', $primero->id)->monto_atrasado_hoy);
        $this->assertSame(100.0, (float) collect($reporte['pagos'])->firstWhere('id', $segundo->id)->monto_atrasado_hoy);
    }

    public function test_gestor_daily_payments_include_advances_and_ticket_data_only_for_its_portfolio(): void
    {
        $advisor = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora']);
        $otherAdvisor = Asesor::create(['id_asesor' => 'ASE-002', 'nombre_asesor' => 'Otro Gestor']);
        $cliente = $this->cliente('CLI-001', 'Cliente anticipado');
        $credito = $this->credito($advisor, $cliente, 'Activo', '2026-09-12', 3);
        $otro = $this->credito($otherAdvisor, $cliente, 'Activo', '2026-09-12', 3);
        $pago = Pago::create(['num_prog' => $credito->num_prog, 'monto' => 100, 'fecha' => '2026-09-05', 'hora' => '09:00:00', 'tipo' => 'Abono']);
        Pago::create(['num_prog' => $otro->num_prog, 'monto' => 100, 'fecha' => '2026-09-05', 'tipo' => 'Abono']);
        Pago::create(['num_prog' => $credito->num_prog, 'monto' => 10, 'fecha' => '2026-09-05', 'tipo' => 'Multa']);
        Pago::create(['num_prog' => $credito->num_prog, 'monto' => 100, 'fecha' => '2026-09-06', 'tipo' => 'Abono']);

        $reporte = app(CarteraService::class)->cobrosDelDia('2026-09-05', $advisor->id);
        $this->assertSame([], $reporte['cobros']);
        $this->assertSame([$pago->id], $reporte['pagos']->pluck('id')->all());
        $this->assertSame([$pago->id], $reporte['pagos_anticipados']->pluck('id')->all());
        $this->assertSame(100.0, $reporte['monto_anticipado']);
        $this->assertSame(100.0, $reporte['monto_cobrado']);
        $ticket = $reporte['pagos']->first()->toArray();
        $this->assertSame('Cliente anticipado', $ticket['credito']['cliente']['nombre_completo']);
        $this->assertSame('Ana Gestora', $ticket['credito']['asesor']['nombre_asesor']);
        $this->assertSame('09:00:00', $ticket['hora']);
        $this->assertSame(100.0, (float) $ticket['monto']);
    }

    public function test_gestor_daily_report_includes_payment_number_for_ticket_reprints(): void
    {
        $advisor = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora']);
        $cliente = $this->cliente('CLI-001', 'Cliente Ana');
        $credito = $this->credito($advisor, $cliente, 'Activo', '2026-08-01', 4);

        Pago::create([
            'num_prog' => $credito->num_prog,
            'monto' => 100,
            'fecha' => '2026-08-08',
            'hora' => '09:00:00',
            'tipo' => 'Abono',
        ]);
        $segundoPago = Pago::create([
            'num_prog' => $credito->num_prog,
            'monto' => 100,
            'fecha' => '2026-08-15',
            'hora' => '09:00:00',
            'tipo' => 'Abono',
        ]);

        $reporte = app(CarteraService::class)->cobrosDelDia('2026-08-15', $advisor->id);
        $pagoTicket = collect($reporte['pagos'])->firstWhere('id', $segundoPago->id);

        $this->assertSame(2, $pagoTicket->num_pago);
        $this->assertSame(4, $pagoTicket->total_pagos);
    }

    public function test_gestor_daily_advances_split_mixed_payments_and_keep_final_payment_ticket(): void
    {
        $advisor = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora']);
        $cliente = $this->cliente('CLI-001', 'Cliente con atrasos');
        $credito = $this->credito($advisor, $cliente, 'Finalizado', '2026-08-01', 4);
        Pago::create(['num_prog' => $credito->num_prog, 'monto' => 100, 'fecha' => '2026-08-07', 'tipo' => 'Abono']);
        $mixto = Pago::create(['num_prog' => $credito->num_prog, 'monto' => 150, 'fecha' => '2026-08-14', 'hora' => '09:00:00', 'tipo' => 'Abono']);
        $final = Pago::create(['num_prog' => $credito->num_prog, 'monto' => 150, 'fecha' => '2026-08-14', 'hora' => '10:00:00', 'tipo' => 'Abono']);

        $reporte = app(CarteraService::class)->cobrosDelDia('2026-08-14', $advisor->id);
        $this->assertSame([$mixto->id, $final->id], $reporte['pagos']->pluck('id')->all());
        $this->assertSame([$final->id], $reporte['pagos_anticipados']->pluck('id')->all());
        $this->assertSame([50.0, 50.0], $reporte['pagos']->pluck('monto_extra_hoy')->all());
        $this->assertSame(100.0, $reporte['monto_anticipado']);
        $this->assertSame(300.0, $reporte['monto_cobrado']);
        $this->assertSame(150.0, (float) $reporte['pagos']->first()->monto);
    }

    public function test_quincenal_credit_is_due_on_its_fixed_month_days_not_on_its_weekday(): void
    {
        $advisor = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora']);
        $cliente = $this->cliente('CLI-001', 'Cliente quincenal');
        // Cuotas los días 15 y 30: 15-ago (sábado), 30-ago (domingo), 15-sep (martes).
        $credito = $this->credito($advisor, $cliente, 'Activo', '2026-08-15', 3);
        $credito->update(['frecuencia_pago' => Credito::FRECUENCIA_QUINCENAL, 'dia_quincena_1' => 15, 'dia_quincena_2' => 30]);
        Pago::create(['num_prog' => $credito->num_prog, 'monto' => 100, 'fecha' => '2026-08-15', 'hora' => '09:00:00', 'tipo' => 'Abono']);

        $sabadoSinCuota = collect(app(CarteraService::class)->cobrosDelDia('2026-08-22', $advisor->id)['cobros'])
            ->firstWhere('num_prog', $credito->num_prog);
        $this->assertNull($sabadoSinCuota);

        $cobro = collect(app(CarteraService::class)->cobrosDelDia('2026-08-30', $advisor->id)['cobros'])
            ->firstWhere('num_prog', $credito->num_prog);
        $this->assertNotNull($cobro);
        $this->assertSame('del_dia', $cobro['categoria']);
        $this->assertSame('2026-08-30', $cobro['pendientes'][0]['fecha']);
        $this->assertSame(100.0, $cobro['monto_a_cobrar']);
    }

    public function test_daily_collection_exposes_mora_and_counts_its_payment(): void
    {
        $advisor = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora']);
        $cliente = $this->cliente('CLI-001', 'Cliente Ana');
        $credito = $this->credito($advisor, $cliente, 'EnMora', '2026-08-01', 2);

        Pago::create([
            'num_prog' => $credito->num_prog,
            'monto' => 50,
            'fecha' => '2026-08-08',
            'hora' => '09:00:00',
            'tipo' => 'Abono',
        ]);

        $reporte = app(CarteraService::class)->cobrosDelDia('2026-08-08', $advisor->id);

        $this->assertSame(50.0, $reporte['monto_cobrado']);
        $this->assertCount(0, $reporte['pagos_anticipados']);
        $this->assertSame(0.0, $reporte['monto_anticipado']);
        $this->assertCount(1, $reporte['creditos_mora']);
        $this->assertTrue($reporte['creditos_mora'][0]['pagado_hoy']);
    }

    public function test_gestor_daily_collection_shows_confirmed_cash_delivery_read_only(): void
    {
        $advisor = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora']);
        $cliente = $this->cliente('CLI-001', 'Cliente Ana');
        $credito = $this->credito($advisor, $cliente, 'Activo', '2026-08-01', 2);
        Pago::create(['num_prog' => $credito->num_prog, 'monto' => 150, 'fecha' => '2026-08-08', 'tipo' => 'Abono']);
        RecepcionAsesor::create([
            'fecha' => '2026-08-08',
            'id_asesor' => $advisor->id,
            'monto_esperado' => 150,
            'monto_recibido' => 100,
        ]);

        $reporte = app(CarteraService::class)->cobrosDelDia('2026-08-08', $advisor->id);

        $this->assertTrue($reporte['recepcion_confirmada']);
        $this->assertSame(100.0, $reporte['entregado_caja']);
        $this->assertSame(50.0, $reporte['pendiente_entrega_caja']);
    }

    public function test_admin_daily_report_only_lists_collection_managers(): void
    {
        $gestor = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora', 'rol_laboral' => 'Gestor de Cobranza']);
        $gerente = Asesor::create(['id_asesor' => 'ASE-002', 'nombre_asesor' => 'Beto Gerencia', 'rol_laboral' => 'Gerencia']);
        $clienteGestor = $this->cliente('CLI-001', 'Cliente Gestor');
        $clienteGerente = $this->cliente('CLI-002', 'Cliente Gerencia');
        $creditoGestor = $this->credito($gestor, $clienteGestor, 'Activo', '2026-08-08', 2);
        $creditoGerente = $this->credito($gerente, $clienteGerente, 'Activo', '2026-08-08', 2);

        Pago::create(['num_prog' => $creditoGestor->num_prog, 'monto' => 100, 'fecha' => '2026-08-08', 'hora' => '09:00:00', 'tipo' => 'Abono']);
        Pago::create(['num_prog' => $creditoGerente->num_prog, 'monto' => 100, 'fecha' => '2026-08-08', 'hora' => '09:00:00', 'tipo' => 'Abono']);

        $reporte = app(ReportService::class)->reporteDiario('2026-08-08');

        $this->assertSame(100.0, $reporte['total_abonos']);
        $this->assertSame([$gestor->id], array_column($reporte['por_asesor'], 'id_asesor'));
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

    public function test_closed_portfolio_classifies_renewal_right_and_pending_balance(): void
    {
        $advisor = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Gestora']);
        $clientePuntual = $this->cliente('CLI-020', 'Cliente Puntual');
        $clienteMora = $this->cliente('CLI-021', 'Cliente Con Mora');
        $clienteCerrado = $this->cliente('CLI-022', 'Cliente Con Saldo');
        $puntual = $this->credito($advisor, $clientePuntual, 'Finalizado', '2026-08-01', 4);
        $conMora = $this->credito($advisor, $clienteMora, 'Finalizado', '2026-08-01', 4);
        $conMora->update(['ciclo_inicio_mora' => 2]);
        $cerradoConSaldo = $this->credito($advisor, $clienteCerrado, 'CerradoSinRenovacion', '2026-08-01', 4);

        $conDerecho = app(CarteraController::class)->cerrados(new Request([
            'tipo' => 'individual',
            'seccion' => 'con-derecho-renovacion',
        ]));
        $sinRenovacion = app(CarteraController::class)->cerrados(new Request([
            'tipo' => 'individual',
            'seccion' => 'sin-derecho-renovacion',
        ]));
        $conSaldo = app(CarteraController::class)->cerrados(new Request([
            'tipo' => 'individual',
            'seccion' => 'cerrados-con-saldo',
        ]));

        $this->assertSame([$puntual->num_prog], array_column($conDerecho->getData(true)['data'], 'num_prog'));
        $this->assertSame([$conMora->num_prog], array_column($sinRenovacion->getData(true)['data'], 'num_prog'));
        $this->assertSame([$cerradoConSaldo->num_prog], array_column($conSaldo->getData(true)['data'], 'num_prog'));
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

    public function test_portfolios_only_return_the_logged_in_field_users_credits(): void
    {
        $asesores = [Asesor::create(['nombre_asesor' => 'Ana']), Asesor::create(['nombre_asesor' => 'Beto'])];
        foreach ($asesores as $i => $asesor) {
            foreach (['Activo', 'EnMora', 'CerradoSinRenovacion'] as $j => $estado) {
                foreach (['Individual', 'Grupal'] as $k => $tipo) {
                    $cliente = $this->cliente("CLI-{$i}-{$j}-{$k}", 'Cliente de prueba');
                    $credito = $this->credito($asesor, $cliente, $estado, '2026-08-01', 4);
                    $credito->update(['tipo_credito' => $tipo, 'ciclo_inicio_mora' => $estado === 'CerradoSinRenovacion' ? 1 : null]);
                }
            }
        }
        $endpoints = [
            '/cartera/activa', '/cartera/activa?tipo=individual', '/cartera/activa?tipo=grupal',
            '/cartera/mora', '/cartera/mora-activa', '/cartera/mora-muerta', '/cartera/cerrados',
            '/creditos', '/reportes/cartera?tipo=general', '/reportes/cartera?tipo=individual',
            '/reportes/cartera?tipo=grupal',
        ];
        foreach (['asesor', 'Gestor de Cobranza', 'Asesor Financiero'] as $rol) {
            $user = new \App\Models\User(['id_asesor' => $asesores[0]->id]);
            $user->id = 999;
            $user->setRelation('role', new \App\Models\Role(['nombre' => $rol]));
            $this->actingAs($user, 'api');
            foreach ($endpoints as $endpoint) {
                $url = '/api'.$endpoint.(str_contains($endpoint, '?') ? '&' : '?').'id_asesor='.$asesores[1]->id;
                if ($endpoint === '/cartera/activa') {
                    $this->getJson($url)->assertForbidden();
                    continue;
                }
                $response = $this->getJson($url)->assertOk();
                $rows = $response->json('data') ?? $response->json('creditos');
                $this->assertNotEmpty($rows, $url);
                foreach ($rows as $row) {
                    $this->assertSame($asesores[0]->id, $row['id_asesor'], $url);
                }
            }
        }
        $user->setRelation('role', new \App\Models\Role(['nombre' => 'admin']));
        $response = $this->getJson('/api/cartera/activa')->assertOk();
        $this->assertCount(2, array_unique(array_column($response->json('data'), 'id_asesor')));
    }

    public function test_unlinked_field_accounts_cannot_fall_back_to_all_portfolios(): void
    {
        foreach (['asesor', 'Gestor de Cobranza', 'Asesor Financiero'] as $rol) {
            $user = new \App\Models\User();
            $user->id = 999;
            $user->setRelation('role', new \App\Models\Role(['nombre' => $rol]));
            $this->actingAs($user, 'api');
            foreach (['cartera/activa', 'cartera/activa?tipo=individual', 'cartera/activa?tipo=grupal',
                'cartera/mora', 'cartera/mora-activa', 'cartera/mora-muerta', 'cartera/cerrados',
                'cartera/cobros-del-dia', 'creditos', 'reportes/cartera'] as $endpoint) {
                $this->getJson('/api/'.$endpoint.(str_contains($endpoint, '?') ? '&' : '?').'id_asesor=1')
                    ->assertForbidden()->assertJsonPath('message', 'Tu usuario no tiene un gestor vinculado. Solicita a administración que lo asigne.');
            }
        }
    }

    private function createSchema(): void
    {
        foreach (['movimientos_caja', 'recepciones_asesor', 'pagos', 'refinanciamientos', 'creditos', 'grupos', 'clientes', 'asesores'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('asesores', function (Blueprint $table) { $table->id(); $table->string('id_asesor')->nullable(); $table->string('nombre_asesor'); $table->string('rol_laboral')->nullable(); $table->timestamps(); });
        Schema::create('clientes', function (Blueprint $table) { $table->string('id_cliente')->primary(); $table->string('nombre_completo'); $table->timestamps(); });
        Schema::create('grupos', function (Blueprint $table) { $table->id(); $table->string('nombre_grupo'); $table->timestamps(); });
        Schema::create('creditos', function (Blueprint $table) {
            $table->id('num_prog'); $table->string('id_cliente')->nullable(); $table->unsignedBigInteger('id_grupo')->nullable(); $table->unsignedBigInteger('id_asesor');
            $table->date('fecha_otorgacion'); $table->date('fecha_primer_pago')->nullable(); $table->integer('ciclo'); $table->integer('ciclo_inicio_mora')->nullable(); $table->integer('dias_mora_cache')->default(0); $table->decimal('monto_otorgado', 12, 2);
            $table->decimal('interes', 12, 2); $table->decimal('total', 12, 2); $table->decimal('saldo_pendiente', 12, 2)->nullable(); $table->integer('plazos');
            $table->decimal('valor_ficha', 12, 2); $table->string('dias_pago'); $table->string('frecuencia_pago')->default('Semanal'); $table->unsignedTinyInteger('dia_quincena_1')->nullable(); $table->unsignedTinyInteger('dia_quincena_2')->nullable(); $table->string('tipo_credito'); $table->string('estado'); $table->timestamps();
            $table->unsignedBigInteger('credito_padre_id')->nullable(); $table->text('tabla_amortizacion')->nullable();
        });
        Schema::create('refinanciamientos', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('num_prog_anterior'); $table->unsignedBigInteger('num_prog_nuevo');
            $table->decimal('saldo_anterior', 12, 2); $table->decimal('deduccion', 12, 2); $table->decimal('monto_neto', 12, 2);
            $table->date('fecha_efectiva')->nullable(); $table->timestamps();
        });
        Schema::create('pagos', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('num_prog'); $table->decimal('monto', 12, 2); $table->date('fecha'); $table->time('hora')->nullable(); $table->string('tipo'); $table->timestamps();
        });
        Schema::create('movimientos_caja', function (Blueprint $table) {
            $table->id(); $table->date('fecha'); $table->unsignedBigInteger('id_asesor')->nullable(); $table->text('motivo');
            $table->string('tipo'); $table->decimal('monto', 14, 2); $table->decimal('saldo_resultante', 14, 2)->nullable();
            $table->string('categoria')->nullable(); $table->string('cuenta')->nullable(); $table->unsignedBigInteger('num_prog')->nullable();
            $table->unsignedBigInteger('pago_id')->nullable(); $table->string('referencia')->nullable(); $table->unsignedBigInteger('registrado_por')->nullable(); $table->timestamps();
        });
        Schema::create('recepciones_asesor', function (Blueprint $table) {
            $table->id(); $table->date('fecha'); $table->unsignedBigInteger('id_asesor');
            $table->decimal('monto_esperado', 12, 2); $table->decimal('monto_recibido', 12, 2); $table->text('notas')->nullable(); $table->timestamps();
        });
    }
}
