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
        ]);
        $flujoCaja = app(FlujoCajaService::class);

        $flujoCaja->registrarDesdeDesembolso($credito, 1000);
        $credito->update(['monto_otorgado' => 1500]);
        $flujoCaja->sincronizarDesembolso($credito);

        $referencia = "DESEMBOLSO-{$credito->num_prog}";
        $this->assertSame(1, MovimientoCaja::where('referencia', $referencia)->count());
        $this->assertDatabaseHas('movimientos_caja', [
            'referencia' => $referencia,
            'tipo' => 'Egreso',
            'monto' => 1500.00,
        ]);
    }
}
