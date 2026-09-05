<?php

namespace Tests\Feature;

use App\Models\Asesor;
use App\Models\Cliente;
use App\Models\Credito;
use App\Models\CicloHistorial;
use App\Models\Grupo;
use App\Models\IndicadorOperativoEvento;
use App\Models\MovimientoCaja;
use App\Models\Pago;
use App\Models\Refinanciamiento;
use App\Services\RenovacionHistoricaImportService;
use App\Services\CarteraService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RenovacionHistoricaImportTest extends TestCase
{
    private RenovacionHistoricaImportService $service;
    private Asesor $asesor;
    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createImportSchema();
        $this->service = app(RenovacionHistoricaImportService::class);
        $this->asesor = Asesor::create(['nombre_asesor' => 'Gestor de prueba']);
        $this->cliente = Cliente::create([
            'id_cliente' => 'CLI-001',
            'id_asesor' => $this->asesor->id,
            'nombre_completo' => 'Cliente de prueba',
            'curp' => 'TEST000101HNLXXX01',
            'clave_elector' => 'CLAVE-001',
            'telefono' => '8110000000',
            'direccion' => 'Calle 1',
            'entre_calles' => 'A y B',
            'ocupacion' => 'Comerciante',
            'direccion_trabajo' => 'Local 1',
            'telefono_trabajo' => '8110000001',
        ]);
    }

    public function test_previsualiza_errores_de_columnas_folios_y_conflictos_del_archivo(): void
    {
        [$anterior, $nuevo, $otro] = [$this->credito(), $this->credito(), $this->credito()];

        $preview = $this->service->previsualizar([
            $this->row($anterior->num_prog, $nuevo->num_prog),
            $this->row($anterior->num_prog, $otro->num_prog, 3),
            $this->row($nuevo->num_prog, $nuevo->num_prog, 4),
            $this->row(999999, $otro->num_prog, 5),
        ], ['folio_credito_anterior', 'folio_credito_nuevo', 'saldo_absorbido']);

        $this->assertSame(0, $preview['summary']['valid']);
        $this->assertContains('monto_neto', $preview['missing_columns']);
        $this->assertNotEmpty($preview['rows'][1]['errors']);
        $this->assertNotEmpty($preview['rows'][2]['errors']);
        $this->assertNotEmpty($preview['rows'][3]['errors']);
    }

    public function test_importa_y_reimporta_una_renovacion_historica_sin_movimientos_operativos(): void
    {
        [$anterior, $nuevo] = [$this->credito('Individual'), $this->credito('Individual')];
        $anterior->update([
            'fecha_programada_renovacion' => '2026-08-15',
            'renovacion_autorizada' => 'Autorizado',
            'renovacion_tasa' => 'TCIP18',
        ]);
        $row = $this->row($anterior->num_prog, $nuevo->num_prog);

        $result = $this->service->confirmar([$row], RenovacionHistoricaImportService::REQUIRED_COLUMNS);

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('refinanciamientos', [
            'num_prog_anterior' => $anterior->num_prog,
            'num_prog_nuevo' => $nuevo->num_prog,
            'saldo_anterior' => 250.00,
            'deduccion' => 250.00,
            'monto_neto' => 750.00,
        ]);
        $this->assertSame('2026-08-15', Refinanciamiento::firstOrFail()->fecha_efectiva->format('Y-m-d'));
        $this->assertSame('Finalizado', $anterior->fresh()->estado);
        $this->assertSame(0.0, (float) $anterior->fresh()->saldo_pendiente);
        $this->assertNull($anterior->fresh()->fecha_programada_renovacion);
        $this->assertSame('Pendiente', $anterior->fresh()->renovacion_autorizada);
        $this->assertSame($anterior->num_prog, $nuevo->fresh()->credito_padre_id);
        $this->assertDatabaseHas('ciclos_historial', [
            'num_prog' => $anterior->num_prog,
            'resultado' => 'Refinanciado',
        ]);
        $this->assertSame('2026-08-15', CicloHistorial::where('num_prog', $anterior->num_prog)->firstOrFail()->fecha_fin->format('Y-m-d'));
        $this->assertDatabaseHas('ciclos_historial', [
            'num_prog' => $nuevo->num_prog,
            'resultado' => 'Activo',
        ]);
        $this->assertSame('2026-08-01', CicloHistorial::where('num_prog', $nuevo->num_prog)->firstOrFail()->fecha_inicio->format('Y-m-d'));
        $this->assertSame(0, Pago::count());
        $this->assertSame(0, MovimientoCaja::count());
        $this->assertSame(0, IndicadorOperativoEvento::count());
        $cobros = app(CarteraService::class)->cobrosDelDia('2026-08-15')['cobros'];
        $this->assertNotContains($anterior->num_prog, array_column($cobros, 'num_prog'));

        $row['saldo_absorbido'] = 300;
        $row['monto_neto'] = 700;
        $row['fecha_efectiva'] = '2026-08-22';
        $reimport = $this->service->confirmar([$row], RenovacionHistoricaImportService::REQUIRED_COLUMNS);

        $this->assertSame(1, $reimport['updated']);
        $this->assertSame(1, Refinanciamiento::count());
        $this->assertDatabaseHas('refinanciamientos', ['saldo_anterior' => 300.00, 'monto_neto' => 700.00]);
        $this->assertSame('2026-08-22', Refinanciamiento::firstOrFail()->fecha_efectiva->format('Y-m-d'));
    }

    public function test_importa_renovacion_grupal_y_advierte_diferencias_sin_bloquear(): void
    {
        $grupo = Grupo::create(['nombre_grupo' => 'Grupo prueba', 'id_asesor' => $this->asesor->id]);
        $anterior = $this->credito('Grupal', $grupo->id);
        $nuevo = $this->credito('Grupal', $grupo->id);
        $row = $this->row($anterior->num_prog, $nuevo->num_prog);

        $preview = $this->service->previsualizar([$row], RenovacionHistoricaImportService::REQUIRED_COLUMNS);
        $this->assertSame(1, $preview['summary']['valid']);
        $this->service->confirmar([$row], RenovacionHistoricaImportService::REQUIRED_COLUMNS);

        $this->assertSame('Finalizado', $anterior->fresh()->estado);
        $this->assertSame($anterior->num_prog, $nuevo->fresh()->credito_padre_id);
    }

    private function credito(string $tipo = 'Individual', ?int $grupoId = null): Credito
    {
        return Credito::create([
            'id_cliente' => $tipo === 'Individual' ? $this->cliente->id_cliente : null,
            'id_grupo' => $grupoId,
            'id_asesor' => $this->asesor->id,
            'fecha_otorgacion' => '2026-08-01',
            'fecha_primer_pago' => '2026-08-08',
            'ciclo' => 1,
            'monto_otorgado' => 1000,
            'interes' => 100,
            'total' => 1100,
            'saldo_pendiente' => 1100,
            'plazos' => 12,
            'valor_ficha' => 91.67,
            'dias_pago' => 'SABADO',
            'tipo_credito' => $tipo,
            'estado' => 'Activo',
        ]);
    }

    private function row(int $anterior, int $nuevo, int $rowNumber = 2): array
    {
        return [
            'row_number' => $rowNumber,
            'folio_credito_anterior' => $anterior,
            'folio_credito_nuevo' => $nuevo,
            'saldo_absorbido' => 250,
            'monto_neto' => 750,
            'fecha_efectiva' => '2026-08-15',
            'intereses_arrastrados' => 0,
            'notas' => 'Importación de prueba',
        ];
    }

    private function createImportSchema(): void
    {
        foreach (['indicadores_operativos_eventos', 'movimientos_caja', 'pagos', 'ciclos_historial', 'refinanciamientos', 'creditos', 'grupos', 'clientes', 'asesores'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('asesores', function (Blueprint $table) { $table->id(); $table->string('nombre_asesor'); $table->timestamps(); });
        Schema::create('clientes', function (Blueprint $table) {
            $table->string('id_cliente')->primary(); $table->unsignedBigInteger('id_asesor')->nullable(); $table->string('nombre_completo');
            $table->string('curp')->unique(); $table->string('clave_elector'); $table->string('telefono'); $table->text('direccion');
            $table->string('entre_calles'); $table->string('ocupacion'); $table->text('direccion_trabajo'); $table->string('telefono_trabajo'); $table->timestamps();
        });
        Schema::create('grupos', function (Blueprint $table) { $table->id(); $table->string('nombre_grupo'); $table->unsignedBigInteger('id_asesor')->nullable(); $table->timestamps(); });
        Schema::create('creditos', function (Blueprint $table) {
            $table->id('num_prog'); $table->string('id_cliente')->nullable(); $table->unsignedBigInteger('id_grupo')->nullable(); $table->unsignedBigInteger('id_asesor');
            $table->date('fecha_otorgacion'); $table->date('fecha_primer_pago')->nullable(); $table->integer('ciclo'); $table->decimal('monto_otorgado', 12, 2);
            $table->decimal('interes', 12, 2); $table->decimal('total', 12, 2); $table->decimal('saldo_pendiente', 12, 2)->nullable(); $table->integer('plazos');
            $table->decimal('valor_ficha', 12, 2); $table->string('dias_pago'); $table->string('tipo_credito'); $table->string('estado');
            $table->unsignedBigInteger('credito_padre_id')->nullable(); $table->date('fecha_programada_renovacion')->nullable();
            $table->string('renovacion_autorizada')->nullable(); $table->string('renovacion_tasa')->nullable(); $table->timestamps();
        });
        Schema::create('refinanciamientos', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('num_prog_anterior')->unique(); $table->unsignedBigInteger('num_prog_nuevo')->unique();
            $table->decimal('saldo_anterior', 12, 2); $table->decimal('deduccion', 12, 2); $table->decimal('monto_neto', 12, 2);
            $table->decimal('intereses_arrastrados', 12, 2)->default(0); $table->date('fecha_efectiva')->nullable(); $table->text('notas')->nullable(); $table->timestamps();
        });
        Schema::create('ciclos_historial', function (Blueprint $table) {
            $table->id(); $table->string('id_cliente')->nullable(); $table->unsignedBigInteger('id_grupo')->nullable(); $table->integer('ciclo');
            $table->unsignedBigInteger('num_prog'); $table->date('fecha_inicio'); $table->date('fecha_fin')->nullable(); $table->date('fecha_consulta')->nullable();
            $table->string('resultado'); $table->json('snapshot')->nullable(); $table->timestamps();
        });
        Schema::create('pagos', function (Blueprint $table) { $table->id(); $table->unsignedBigInteger('num_prog')->nullable(); $table->decimal('monto', 12, 2)->default(0); $table->timestamps(); });
        Schema::create('movimientos_caja', function (Blueprint $table) { $table->id(); $table->timestamps(); });
        Schema::create('indicadores_operativos_eventos', function (Blueprint $table) { $table->id(); $table->timestamps(); });
    }
}
