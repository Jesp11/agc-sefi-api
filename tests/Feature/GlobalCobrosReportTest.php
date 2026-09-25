<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GlobalCobrosReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2024-02-29 10:00:00');
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email'); $t->string('password');
            $t->unsignedBigInteger('role_id'); $t->unsignedBigInteger('id_asesor')->nullable(); $t->timestamps();
        });
        Schema::create('asesores', function (Blueprint $t) { $t->id(); $t->string('nombre_asesor'); });
        Schema::create('creditos', function (Blueprint $t) {
            $t->id('num_prog'); $t->unsignedBigInteger('id_asesor')->nullable(); $t->string('tipo_credito')->default('Individual');
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('num_prog'); $t->decimal('monto', 12, 2); $t->date('fecha'); $t->string('tipo');
        });
        Schema::create('pago_grupal_asignaciones', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('pago_id'); });
        DB::table('asesores')->insert([['id' => 1, 'nombre_asesor' => 'Ana'], ['id' => 2, 'nombre_asesor' => 'Beto']]);
        DB::table('creditos')->insert([
            ['num_prog' => 1, 'id_asesor' => 1, 'tipo_credito' => 'Grupal'],
            ['num_prog' => 2, 'id_asesor' => 2, 'tipo_credito' => 'Individual'],
            ['num_prog' => 3, 'id_asesor' => null, 'tipo_credito' => 'Individual'],
        ]);
        $this->loginAs('admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function loginAs(string $role, ?int $advisor = null): void
    {
        $role = Role::firstOrCreate(['nombre' => $role]);
        $this->actingAs(User::create([
            'name' => 'Reporte', 'email' => uniqid().'@test.local', 'password' => 'secret',
            'role_id' => $role->id, 'id_asesor' => $advisor,
        ]), 'api');
    }

    private function pago(int $credito, string $fecha, float $monto, string $tipo = 'Abono'): int
    {
        return DB::table('pagos')->insertGetId(['num_prog' => $credito, 'fecha' => $fecha, 'monto' => $monto, 'tipo' => $tipo]);
    }

    public function test_week_groups_days_then_advisors_without_duplicating_group_payments(): void
    {
        $pago = $this->pago(1, '2024-02-26', 100.15);
        DB::table('pago_grupal_asignaciones')->insert([['pago_id' => $pago], ['pago_id' => $pago]]);
        $this->pago(1, '2024-02-26', 0.20, 'Multa');
        $this->pago(2, '2024-02-26', 50.10);
        $this->pago(3, '2024-02-29', 10);
        $this->pago(1, '2024-03-01', 5);
        $this->pago(1, '2024-03-02', 900);
        $this->pago(1, '2024-03-03', 900);
        $this->pago(1, '2024-02-25', 900);
        $this->pago(1, '2024-02-26', 900, 'Otro');

        $data = $this->getJson('/api/reportes/global-cobros?periodo=semana&fecha=2024-02-29')
            ->assertOk()->assertJsonPath('inicio', '2024-02-26')->assertJsonPath('fin', '2024-02-29')
            ->assertJsonCount(4, 'dias')->assertJsonCount(2, 'dias.0.por_asesor')
            ->assertJsonPath('dias.0.por_asesor.0.nombre_asesor', 'Ana')
            ->assertJsonPath('dias.0.por_asesor.0.total_cobrado', 100.15)
            ->assertJsonPath('dias.0.totales.total_cobrado', 150.25)
            ->assertJsonPath('dias.1.por_asesor', [])
            ->assertJsonPath('dias.3.por_asesor.0.nombre_asesor', 'Sin asesor')
            ->assertJsonPath('totales.total_cobrado', 160.25)->json();
        $this->assertEquals(160.25, array_sum(array_column(array_column($data['dias'], 'totales'), 'total_cobrado')));
    }

    public function test_month_groups_workweeks_clipped_to_month_and_totals_each_advisor(): void
    {
        $this->pago(1, '2026-09-01', 100.15);
        $this->pago(1, '2026-09-04', 50.10);
        $this->pago(1, '2026-09-03', 0.20, 'Multa');
        $this->pago(2, '2026-09-04', 20);
        $this->pago(1, '2026-09-07', 30);
        $this->pago(3, '2026-09-30', 10);
        foreach (['2026-08-31', '2026-09-05', '2026-09-06', '2026-10-01'] as $date) {
            $this->pago(1, $date, 900);
        }
        $data = $this->getJson('/api/reportes/global-cobros?periodo=mes&fecha=2026-09-15')
            ->assertOk()->assertJsonCount(5, 'semanas')->assertJsonPath('dias', [])
            ->assertJsonPath('semanas.0.numero', 1)
            ->assertJsonPath('semanas.0.inicio', '2026-09-01')->assertJsonPath('semanas.0.fin', '2026-09-04')
            ->assertJsonPath('semanas.1.inicio', '2026-09-07')->assertJsonPath('semanas.1.fin', '2026-09-11')
            ->assertJsonPath('semanas.4.inicio', '2026-09-28')->assertJsonPath('semanas.4.fin', '2026-09-30')
            ->assertJsonPath('semanas.0.por_asesor.0.abonos', 150.25)
            ->assertJsonPath('semanas.0.por_asesor.0.multas', 0.2)
            ->assertJsonPath('semanas.0.por_asesor.0.total_cobrado', 150.25)
            ->assertJsonPath('semanas.0.totales.total_cobrado', 170.25)
            ->assertJsonPath('semanas.2.por_asesor', [])
            ->assertJsonPath('semanas.2.totales.total_cobrado', 0)
            ->assertJsonPath('totales.total_cobrado', 210.25)->json();
        $this->assertEquals(210.25, array_sum(array_column(array_column($data['semanas'], 'totales'), 'total_cobrado')));
        $this->getJson('/api/reportes/global-cobros?periodo=mes&fecha=2026-09-01&id_asesor=2')
            ->assertOk()->assertJsonCount(1, 'semanas.0.por_asesor')->assertJsonPath('totales.total_cobrado', 20);
        $this->loginAs('asesor', 1);
        $this->getJson('/api/reportes/global-cobros?periodo=mes&fecha=2026-09-01&id_asesor=2')
            ->assertOk()->assertJsonPath('totales.total_cobrado', 180.25);
    }

    public function test_month_handles_leap_day_and_weekend_month_boundaries(): void
    {
        $this->pago(1, '2024-02-29', 10);
        $this->getJson('/api/reportes/global-cobros?periodo=mes&fecha=2024-02-15')
            ->assertOk()->assertJsonCount(5, 'semanas')
            ->assertJsonPath('semanas.4.fin', '2024-02-29')->assertJsonPath('totales.total_cobrado', 10);
        $this->getJson('/api/reportes/global-cobros?periodo=mes&fecha=2026-08-01')
            ->assertOk()->assertJsonCount(5, 'semanas')
            ->assertJsonPath('semanas.0.inicio', '2026-08-03')
            ->assertJsonPath('semanas.4.inicio', '2026-08-31')->assertJsonPath('semanas.4.fin', '2026-08-31');
        $this->getJson('/api/reportes/global-cobros?periodo=mes&fecha=2026-05-01')
            ->assertOk()->assertJsonCount(5, 'semanas')->assertJsonPath('semanas.4.fin', '2026-05-29');
    }

    public function test_filters_and_enforces_field_scope_and_missing_advisor(): void
    {
        $this->pago(1, '2024-02-29', 10);
        $this->pago(2, '2024-02-29', 20);
        $url = '/api/reportes/global-cobros?periodo=semana&fecha=2024-02-29&id_asesor=2';
        $this->getJson($url)->assertOk()->assertJsonPath('totales.total_cobrado', 20);
        foreach (['asesor', 'Gestor de Cobranza', 'Asesor Financiero'] as $role) {
            $this->loginAs($role, 1);
            $this->getJson($url)->assertOk()->assertJsonPath('id_asesor', 1)->assertJsonPath('totales.total_cobrado', 10);
        }
        $this->loginAs('asesor');
        $this->getJson($url)->assertForbidden();
    }

    public function test_week_is_clipped_to_selected_month_at_both_ends(): void
    {
        foreach (['2026-08-31', '2026-09-01', '2026-09-04', '2026-09-28', '2026-09-30', '2026-10-01'] as $date) {
            $this->pago(1, $date, 10);
        }
        $this->getJson('/api/reportes/global-cobros?periodo=semana&fecha=2026-09-01')
            ->assertOk()->assertJsonPath('inicio', '2026-09-01')->assertJsonPath('fin', '2026-09-04')
            ->assertJsonCount(4, 'dias')->assertJsonPath('totales.total_cobrado', 20);
        $this->getJson('/api/reportes/global-cobros?periodo=semana&fecha=2026-09-30')
            ->assertOk()->assertJsonPath('inicio', '2026-09-28')->assertJsonPath('fin', '2026-09-30')
            ->assertJsonCount(3, 'dias')->assertJsonPath('totales.total_cobrado', 20);
        // A month starting on a weekend selects its first working week.
        $this->getJson('/api/reportes/global-cobros?periodo=semana&fecha=2026-08-01')
            ->assertOk()->assertJsonPath('inicio', '2026-08-03')->assertJsonPath('fin', '2026-08-07')
            ->assertJsonCount(5, 'dias');
    }

    public function test_fines_remain_visible_but_never_increase_collection_totals(): void
    {
        $this->pago(1, '2026-09-01', 25, 'Multa');
        foreach (['semana' => 'dias', 'mes' => 'semanas'] as $periodo => $detalle) {
            $this->getJson("/api/reportes/global-cobros?periodo={$periodo}&fecha=2026-09-01")
                ->assertOk()->assertJsonPath('totales.abonos', 0)
                ->assertJsonPath('totales.multas', 25)->assertJsonPath('totales.total_cobrado', 0)
                ->assertJsonPath("{$detalle}.0.totales.multas", 25)
                ->assertJsonPath("{$detalle}.0.totales.total_cobrado", 0)
                ->assertJsonPath("{$detalle}.0.por_asesor.0.multas", 25)
                ->assertJsonPath("{$detalle}.0.por_asesor.0.total_cobrado", 0);
        }
    }

    public function test_default_date_uses_the_application_timezone(): void
    {
        $this->getJson('/api/reportes/global-cobros?periodo=semana')
            ->assertOk()->assertJsonPath('fecha_base', now()->toDateString())
            ->assertJsonPath('inicio', '2024-02-26')->assertJsonPath('fin', '2024-02-29');
    }

    public function test_empty_period_and_invalid_parameters(): void
    {
        $this->getJson('/api/reportes/global-cobros?periodo=semana&fecha=2024-02-29')
            ->assertOk()->assertJsonCount(4, 'dias')->assertJsonPath('totales.total_cobrado', 0);
        foreach (['', '?periodo=dia&fecha=2024-02-29', '?periodo=mes&fecha=2024-02-30', '?periodo=mes&fecha=2024-02-01&id_asesor=999', '?periodo[]=mes&fecha=2024-02-01'] as $query) {
            $this->getJson('/api/reportes/global-cobros'.$query)->assertUnprocessable();
        }
    }
}
