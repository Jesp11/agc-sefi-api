<?php

namespace Tests\Feature;

use App\Models\Asesor;
use App\Models\ConfirmacionMovimiento;
use App\Models\NominaPeriodo;
use App\Services\FlujoCajaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NominaHistorialTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        $tables = [
            'asesores' => ['id_asesor', 'nombre_asesor', 'cumpleanos', 'rfc', 'curp', 'nss', 'banco', 'cuenta_bancaria'],
            'empleados' => ['nombre'],
            'nomina_periodos' => ['fecha_inicio', 'fecha_fin', 'referencia', 'firma_director_administrativo', 'firma_director_operativo', 'registrado_por'],
            'nomina_detalle' => ['periodo_id', 'asesor_id', 'empleado_id', 'detalle_ajustes'],
            'ahorros_personal' => ['asesor_id'],
            'ahorro_personal_movimientos' => ['ahorro_personal_id', 'tipo', 'fecha', 'notas', 'registrado_por'],
            'movimientos_capital' => ['tipo', 'referencia', 'fecha', 'descripcion', 'registrado_por'],
            'confirmaciones_movimientos' => ['fecha', 'id_asesor', 'motivo', 'categoria', 'cuenta', 'num_prog', 'solicitado_por', 'movimiento_caja_id', 'confirmado_por', 'confirmado_at'],
            'movimientos_caja' => ['fecha', 'id_asesor', 'motivo', 'tipo', 'categoria', 'cuenta', 'num_prog', 'pago_id', 'referencia', 'registrado_por'],
        ];
        $amounts = [
            'nomina_periodos' => ['total_dispersado'],
            'nomina_detalle' => ['sueldo_bruto', 'pago_base', 'despensa', 'apoyo_transporte', 'total_percepciones', 'retencion_ahorro', 'total_deducciones', 'sueldo_neto'],
            'ahorros_personal' => ['saldo'],
            'ahorro_personal_movimientos' => ['monto'],
            'movimientos_capital' => ['monto'],
            'confirmaciones_movimientos' => ['monto'],
            'movimientos_caja' => ['monto', 'saldo_resultante'],
        ];
        foreach ($tables as $name => $columns) {
            Schema::dropIfExists($name);
            Schema::create($name, function (Blueprint $table) use ($name, $columns, $amounts) {
                $table->id();
                foreach ($columns as $column) {
                    $table->string($column)->nullable();
                }
                foreach ($amounts[$name] ?? [] as $column) {
                    $table->decimal($column, 14, 2)->default(0);
                }
                if ($name === 'confirmaciones_movimientos') {
                    $table->string('referencia')->unique();
                    $table->string('estado')->default('Pendiente');
                }
                $table->timestamps();
            });
        }
    }

    private function payload(float $ahorro = 300): array
    {
        $asesor = Asesor::create(['id_asesor' => 'ASE-001', 'nombre_asesor' => 'Ana Original', 'rfc' => 'RFC-ORIGINAL']);
        return [
            'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-15',
            'firma_director_administrativo' => 'Directora',
            'empleados' => [[
                'asesor_id' => $asesor->id, 'pago_base' => 2500,
                'despensa' => 300, 'apoyo_transporte' => 200, 'ahorro' => $ahorro,
            ]],
        ];
    }

    public function test_savings_and_net_pay_generate_separate_confirmations_and_balance_capital(): void
    {
        $this->postJson('/api/nomina', $this->payload())->assertCreated()
            ->assertJsonPath('data.total_dispersado', '2700.00');

        $this->assertDatabaseHas('ahorros_personal', ['saldo' => 300]);
        $this->assertDatabaseCount('ahorro_personal_movimientos', 1);
        $this->assertDatabaseHas('movimientos_capital', ['tipo' => 'Nomina', 'monto' => -3000]);
        $this->assertDatabaseCount('confirmaciones_movimientos', 2);
        $this->assertDatabaseHas('confirmaciones_movimientos', ['referencia' => 'NOM-1-AHORRO-ASESOR-1', 'monto' => 300, 'estado' => 'Pendiente']);
        $this->assertDatabaseHas('confirmaciones_movimientos', ['referencia' => 'NOM-1-ASESOR-1', 'monto' => 2700]);
        $this->assertDatabaseCount('movimientos_caja', 0);

        foreach (ConfirmacionMovimiento::all() as $confirmacion) {
            app(FlujoCajaService::class)->confirmarEgresoPendiente($confirmacion);
        }
        $this->assertDatabaseCount('movimientos_caja', 2);
        $this->assertDatabaseHas('movimientos_caja', ['referencia' => 'NOM-1-AHORRO-ASESOR-1', 'tipo' => 'Egreso', 'monto' => 300]);
        $this->assertDatabaseHas('movimientos_caja', ['saldo_resultante' => -3000]);
        $this->assertDatabaseHas('ahorros_personal', ['saldo' => 300]);
    }

    public function test_without_savings_only_net_pay_is_requested(): void
    {
        $this->postJson('/api/nomina', $this->payload(0))->assertCreated();
        $this->assertDatabaseCount('confirmaciones_movimientos', 1);
        $this->assertDatabaseHas('confirmaciones_movimientos', ['monto' => 3000]);
        $this->assertDatabaseCount('ahorro_personal_movimientos', 0);
    }

    public function test_all_pay_can_be_saved_even_when_net_is_zero(): void
    {
        $this->postJson('/api/nomina', $this->payload(3000))->assertCreated();
        $this->assertDatabaseCount('confirmaciones_movimientos', 1);
        $this->assertDatabaseHas('confirmaciones_movimientos', ['referencia' => 'NOM-1-AHORRO-ASESOR-1', 'monto' => 3000]);
    }

    public function test_savings_cannot_exceed_pay_and_nothing_is_written(): void
    {
        $this->postJson('/api/nomina', $this->payload(3001))->assertUnprocessable()
            ->assertJsonValidationErrors('empleados.0.ahorro');
        $this->assertDatabaseCount('nomina_periodos', 0);
        $this->assertDatabaseCount('confirmaciones_movimientos', 0);
        $this->assertDatabaseCount('ahorro_personal_movimientos', 0);
    }

    public function test_history_preserves_employee_snapshot_and_reading_it_does_not_repeat_movements(): void
    {
        $this->postJson('/api/nomina', $this->payload())->assertCreated();
        Asesor::first()->update(['nombre_asesor' => 'Nombre Nuevo', 'rfc' => 'RFC-NUEVO']);
        foreach ([1, 2] as $read) {
            $this->getJson('/api/nomina')->assertOk()
                ->assertJsonPath('data.0.detalles.0.detalle_ajustes.empleado.nombre', 'Ana Original')
                ->assertJsonPath('data.0.detalles.0.detalle_ajustes.empleado.rfc', 'RFC-ORIGINAL')
                ->assertJsonPath('data.0.detalles.0.sueldo_neto', '2700.00')
                ->assertJsonPath('data.0.firma_director_administrativo', 'Directora');
        }
        $this->assertDatabaseCount('confirmaciones_movimientos', 2);
        $this->assertDatabaseCount('ahorro_personal_movimientos', 1);
    }

    public function test_history_includes_older_periods_and_paginates_in_stable_order(): void
    {
        foreach (range(1, 11) as $id) {
            NominaPeriodo::create(['fecha_inicio' => '2026-08-01', 'fecha_fin' => '2026-08-15', 'total_dispersado' => 100]);
        }
        $this->getJson('/api/nomina')->assertOk()->assertJsonPath('total', 11)
            ->assertJsonPath('data.0.id', 11)->assertJsonCount(10, 'data');
        $this->getJson('/api/nomina?page=2')->assertOk()->assertJsonPath('data.0.id', 1)
            ->assertJsonCount(1, 'data');
    }

    public function test_confirmation_failure_rolls_back_payroll_and_savings(): void
    {
        $this->mock(FlujoCajaService::class)->shouldReceive('solicitarConfirmacionEgreso')
            ->once()->andThrow(new \RuntimeException('Fallo de prueba'));
        $this->postJson('/api/nomina', $this->payload())->assertStatus(500);
        $this->assertDatabaseCount('nomina_periodos', 0);
        $this->assertDatabaseCount('nomina_detalle', 0);
        $this->assertDatabaseCount('ahorros_personal', 0);
        $this->assertDatabaseCount('ahorro_personal_movimientos', 0);
        $this->assertDatabaseCount('movimientos_capital', 0);
    }
}
