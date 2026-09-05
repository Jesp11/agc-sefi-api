<?php

namespace Tests\Feature;

use App\Models\Asesor;
use App\Models\Cliente;
use App\Models\Credito;
use App\Models\Pago;
use App\Services\PagosRutaImportService;
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

    public function test_creates_a_payment_and_cash_movement_then_skips_the_same_route_reference(): void
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
        $this->assertDatabaseHas('movimientos_caja', ['num_prog' => $credito->num_prog, 'tipo' => 'Ingreso', 'monto' => 100]);
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
        foreach (['movimientos_caja', 'pagos', 'refinanciamientos', 'creditos', 'clientes', 'grupos', 'asesores'] as $table) {
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
    }
}
