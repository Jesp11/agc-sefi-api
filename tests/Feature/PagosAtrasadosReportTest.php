<?php

namespace Tests\Feature;

use App\Models\Asesor;
use App\Models\Cliente;
use App\Models\Credito;
use App\Models\Pago;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
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

    private function createSchema(): void
    {
        foreach (['pagos', 'creditos', 'grupos', 'clientes', 'asesores'] as $table) {
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
        });
        Schema::create('pagos', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('num_prog'); $table->decimal('monto', 12, 2); $table->date('fecha'); $table->time('hora')->nullable(); $table->string('tipo'); $table->timestamps();
        });
    }
}
