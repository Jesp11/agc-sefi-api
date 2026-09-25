<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePermission;
use App\Models\Aportacion;
use App\Models\Inversionista;
use App\Models\LiquidacionInversionista;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CapitalService;
use App\Services\FlujoCajaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LiquidacionInversionistaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'reactivaciones_inversionistas', 'liquidaciones_inversionistas', 'confirmaciones_movimientos', 'movimientos_caja',
            'movimientos_capital', 'aportaciones', 'inversionistas', 'role_permission',
            'permissions', 'roles', 'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('roles', fn (Blueprint $t) => $this->idAndTimestamps($t, fn () => $t->string('nombre')));
        Schema::create('permissions', fn (Blueprint $t) => $this->idAndTimestamps($t, fn () => $t->string('nombre')));
        Schema::create('role_permission', function (Blueprint $t) {
            $t->unsignedBigInteger('role_id'); $t->unsignedBigInteger('permission_id');
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->unsignedBigInteger('role_id')->nullable(); $t->timestamps();
        });
        Schema::create('inversionistas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->decimal('tasa_mensual', 5, 2)->default(0);
            $t->boolean('activo')->default(true); $t->timestamps();
        });
        Schema::create('aportaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('inversionista_id'); $t->decimal('monto', 14, 2);
            $t->date('fecha'); $t->string('tipo'); $t->text('notas')->nullable();
            $t->unsignedBigInteger('registrado_por')->nullable(); $t->timestamps();
        });
        Schema::create('movimientos_capital', function (Blueprint $t) {
            $t->id(); $t->string('tipo'); $t->decimal('monto', 14, 2); $t->string('referencia')->nullable();
            $t->date('fecha'); $t->text('descripcion')->nullable(); $t->unsignedBigInteger('registrado_por')->nullable(); $t->timestamps();
        });
        Schema::create('movimientos_caja', function (Blueprint $t) {
            $t->id(); $t->date('fecha'); $t->unsignedBigInteger('id_asesor')->nullable(); $t->text('motivo');
            $t->string('tipo'); $t->decimal('monto', 14, 2); $t->decimal('saldo_resultante', 14, 2)->nullable();
            $t->string('categoria')->nullable(); $t->string('cuenta')->nullable(); $t->unsignedBigInteger('num_prog')->nullable();
            $t->unsignedBigInteger('pago_id')->nullable(); $t->string('referencia')->nullable();
            $t->unsignedBigInteger('registrado_por')->nullable(); $t->timestamps();
        });
        Schema::create('confirmaciones_movimientos', function (Blueprint $t) {
            $t->id(); $t->date('fecha'); $t->unsignedBigInteger('id_asesor')->nullable(); $t->text('motivo');
            $t->decimal('monto', 14, 2); $t->string('categoria')->nullable(); $t->string('cuenta')->nullable();
            $t->unsignedBigInteger('num_prog')->nullable(); $t->string('referencia')->nullable(); $t->string('estado')->default('Pendiente');
            $t->unsignedBigInteger('movimiento_caja_id')->nullable(); $t->unsignedBigInteger('solicitado_por')->nullable();
            $t->unsignedBigInteger('confirmado_por')->nullable(); $t->timestamp('confirmado_at')->nullable(); $t->timestamps();
        });
        Schema::create('liquidaciones_inversionistas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('inversionista_id'); $t->decimal('capital', 14, 2);
            $t->decimal('rendimiento_final', 14, 2)->default(0); $t->decimal('total', 14, 2); $t->date('fecha');
            $t->string('cuenta'); $t->text('notas')->nullable(); $t->string('estado')->default('Pendiente');
            $t->unsignedBigInteger('confirmacion_movimiento_id')->nullable(); $t->unsignedBigInteger('aportacion_retiro_id')->nullable();
            $t->unsignedBigInteger('aportacion_rendimiento_id')->nullable(); $t->unsignedBigInteger('movimiento_capital_retiro_id')->nullable();
            $t->unsignedBigInteger('movimiento_capital_rendimiento_id')->nullable(); $t->unsignedBigInteger('solicitado_por')->nullable();
            $t->unsignedBigInteger('confirmado_por')->nullable(); $t->timestamp('confirmado_at')->nullable(); $t->timestamps();
        });
        Schema::create('reactivaciones_inversionistas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('inversionista_id'); $t->date('fecha'); $t->text('motivo');
            $t->unsignedBigInteger('realizado_por')->nullable(); $t->timestamps();
        });

        $user = User::create(['name' => 'Contabilidad', 'email' => 'contabilidad@example.test', 'password' => 'secret']);
        Auth::guard('api')->setUser($user);
    }

    public function test_solicita_y_confirma_una_liquidacion_de_forma_atomica(): void
    {
        $inversionista = $this->inversionistaConCapital(50_000);
        $service = app(CapitalService::class);

        $liquidacion = $service->solicitarLiquidacion($inversionista->id, [
            'rendimiento_final' => 2_000,
            'fecha' => '2026-09-23',
            'cuenta' => 'Bancomer',
            'notas' => 'Liquidación solicitada por contrato terminado',
        ]);

        $this->assertSame(LiquidacionInversionista::PENDIENTE, $liquidacion->estado);
        $this->assertSame('52000.00', $liquidacion->total);
        $this->assertTrue($inversionista->fresh()->activo);
        $this->assertDatabaseMissing('aportaciones', ['inversionista_id' => $inversionista->id, 'tipo' => 'Retiro']);

        $service->confirmarLiquidacion($liquidacion, app(FlujoCajaService::class));

        $this->assertFalse($inversionista->fresh()->activo);
        $this->assertSame(LiquidacionInversionista::CONFIRMADA, $liquidacion->fresh()->estado);
        $this->assertDatabaseHas('aportaciones', ['inversionista_id' => $inversionista->id, 'tipo' => 'Retiro', 'monto' => 50_000]);
        $this->assertDatabaseHas('aportaciones', ['inversionista_id' => $inversionista->id, 'tipo' => 'Rendimiento', 'monto' => 2_000]);
        $this->assertDatabaseHas('movimientos_caja', ['referencia' => "LIQ-INV-{$liquidacion->id}", 'monto' => 52_000, 'tipo' => 'Egreso']);
        $this->assertSame(1, \App\Models\MovimientoCaja::where('referencia', "LIQ-INV-{$liquidacion->id}")->count());
    }

    public function test_cancelar_no_afecta_el_capital_ni_el_estado_del_inversionista(): void
    {
        $inversionista = $this->inversionistaConCapital(25_000);
        $service = app(CapitalService::class);
        $liquidacion = $service->solicitarLiquidacion($inversionista->id, [
            'rendimiento_final' => 0, 'fecha' => '2026-09-23', 'cuenta' => 'Efectivo',
        ]);

        $service->cancelarLiquidacion($liquidacion);

        $this->assertTrue($inversionista->fresh()->activo);
        $this->assertSame(LiquidacionInversionista::CANCELADA, $liquidacion->fresh()->estado);
        $this->assertDatabaseMissing('aportaciones', ['inversionista_id' => $inversionista->id, 'tipo' => 'Retiro']);
        $this->assertDatabaseMissing('movimientos_caja', ['referencia' => "LIQ-INV-{$liquidacion->id}"]);
    }

    public function test_bloquea_movimientos_y_confirmacion_si_el_capital_cambia(): void
    {
        $inversionista = $this->inversionistaConCapital(10_000);
        $service = app(CapitalService::class);
        $liquidacion = $service->solicitarLiquidacion($inversionista->id, [
            'rendimiento_final' => 100, 'fecha' => '2026-09-23', 'cuenta' => 'Efectivo',
        ]);

        try {
            $service->registrarPagoRendimiento($inversionista->id, ['monto' => 100, 'fecha' => '2026-09-23']);
            $this->fail('Se esperaba el bloqueo por liquidación pendiente.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('liquidación pendiente', $e->getMessage());
        }

        Aportacion::create(['inversionista_id' => $inversionista->id, 'monto' => 500, 'fecha' => '2026-09-23', 'tipo' => 'Aportacion']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('El capital del inversionista cambió');
        $service->confirmarLiquidacion($liquidacion, app(FlujoCajaService::class));
    }

    public function test_middleware_exige_el_permiso_de_inversionistas(): void
    {
        $role = Role::create(['nombre' => 'gestor_contable']);
        $permission = Permission::create(['nombre' => 'inversionistas.manage']);
        $authorized = User::create(['name' => 'Autorizado', 'role_id' => $role->id]);
        $unauthorized = User::create(['name' => 'Sin permiso']);
        $role->permissions()->attach($permission->id);
        $middleware = new EnsurePermission;

        $request = Request::create('/api/inversionistas/1/liquidacion', 'POST');
        $request->setUserResolver(fn () => $authorized);
        $this->assertSame(200, $middleware->handle($request, fn () => response()->json(['ok' => true]), 'inversionistas.manage')->status());

        $request->setUserResolver(fn () => $unauthorized);
        $this->assertSame(403, $middleware->handle($request, fn () => response()->json(['ok' => true]), 'inversionistas.manage')->status());
    }

    public function test_gerencia_y_contabilidad_pueden_liquidar_y_reactivar_inversionistas(): void
    {
        $permission = Permission::create(['nombre' => 'inversionistas.manage']);
        $roles = collect(['Gerencia', 'Contabilidad', 'Gestor de Cobranza'])
            ->mapWithKeys(fn ($nombre) => [$nombre => Role::create(['nombre' => $nombre])]);

        $migration = require database_path('migrations/2026_09_25_000001_grant_inversionistas_manage_to_finance_roles.php');
        $migration->up();

        foreach (['Gerencia', 'Contabilidad'] as $nombre) {
            $user = User::create(['name' => $nombre, 'role_id' => $roles[$nombre]->id]);
            $this->assertTrue($user->hasPermission($permission->nombre));

            foreach (['liquidacion', 'reactivacion'] as $action) {
                $request = Request::create("/api/inversionistas/1/{$action}", 'POST');
                $request->setUserResolver(fn () => $user);
                $this->assertSame(200, (new EnsurePermission)->handle($request, fn () => response()->json(['ok' => true]), $permission->nombre)->status());
            }
        }

        $gestor = User::create(['name' => 'Gestor', 'role_id' => $roles['Gestor de Cobranza']->id]);
        $this->assertFalse($gestor->hasPermission($permission->nombre));
    }

    public function test_corrige_capital_aportado_y_vigente_sin_generar_movimientos_de_caja(): void
    {
        $inversionista = $this->inversionistaConCapital(50_000);

        app(CapitalService::class)->ajustarCapitalInversionista($inversionista->id, [
            'total_aportaciones' => 45_000,
            'saldo_capital' => 40_000,
            'fecha' => '2026-09-23',
            'motivo' => 'Corrección de saldo importado',
        ]);

        $aportado = (float) Aportacion::where('inversionista_id', $inversionista->id)
            ->where('tipo', 'Aportacion')->sum('monto');
        $retirado = (float) Aportacion::where('inversionista_id', $inversionista->id)
            ->where('tipo', 'Retiro')->sum('monto');

        $this->assertSame(45_000.0, $aportado);
        $this->assertSame(5_000.0, $retirado);
        $this->assertSame(40_000.0, $aportado - $retirado);
        $this->assertDatabaseCount('movimientos_caja', 0);
        $this->assertDatabaseCount('movimientos_capital', 2);
        $this->assertDatabaseHas('aportaciones', [
            'inversionista_id' => $inversionista->id,
            'tipo' => 'Aportacion',
            'monto' => -5_000,
        ]);
        $this->assertDatabaseHas('aportaciones', [
            'inversionista_id' => $inversionista->id,
            'tipo' => 'Retiro',
            'monto' => 5_000,
        ]);
    }

    public function test_rechaza_correccion_con_capital_vigente_mayor_al_total_aportado(): void
    {
        $inversionista = $this->inversionistaConCapital(10_000);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('capital vigente debe estar entre cero');

        app(CapitalService::class)->ajustarCapitalInversionista($inversionista->id, [
            'total_aportaciones' => 8_000,
            'saldo_capital' => 9_000,
            'fecha' => '2026-09-23',
            'motivo' => 'Corrección inválida',
        ]);
    }

    public function test_bloquea_correccion_de_capital_con_liquidacion_pendiente(): void
    {
        $inversionista = $this->inversionistaConCapital(10_000);
        $service = app(CapitalService::class);
        $service->solicitarLiquidacion($inversionista->id, [
            'rendimiento_final' => 0,
            'fecha' => '2026-09-23',
            'cuenta' => 'Efectivo',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('liquidación pendiente');

        $service->ajustarCapitalInversionista($inversionista->id, [
            'total_aportaciones' => 10_000,
            'saldo_capital' => 9_000,
            'fecha' => '2026-09-23',
            'motivo' => 'Intento bloqueado',
        ]);
    }

    public function test_reactiva_un_inversionista_liquidado_con_capital_en_cero_y_auditoria(): void
    {
        $inversionista = $this->inversionistaConCapital(20_000);
        $service = app(CapitalService::class);
        $liquidacion = $service->solicitarLiquidacion($inversionista->id, [
            'rendimiento_final' => 0,
            'fecha' => '2026-09-23',
            'cuenta' => 'Efectivo',
        ]);
        $service->confirmarLiquidacion($liquidacion, app(FlujoCajaService::class));

        $reactivacion = $service->reactivarInversionista($inversionista->id, [
            'fecha' => '2026-09-23',
            'motivo' => 'El inversionista inicia un nuevo ciclo',
        ]);

        $this->assertTrue($inversionista->fresh()->activo);
        $this->assertSame($inversionista->id, $reactivacion->inversionista_id);
        $this->assertDatabaseHas('reactivaciones_inversionistas', [
            'inversionista_id' => $inversionista->id,
            'motivo' => 'El inversionista inicia un nuevo ciclo',
            'realizado_por' => Auth::id(),
        ]);
        $this->assertSame(LiquidacionInversionista::CONFIRMADA, $liquidacion->fresh()->estado);
    }

    public function test_rechaza_reactivar_un_inversionista_que_ya_esta_activo(): void
    {
        $inversionista = $this->inversionistaConCapital(10_000);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ya se encuentra activo');

        app(CapitalService::class)->reactivarInversionista($inversionista->id, [
            'fecha' => '2026-09-23',
            'motivo' => 'Intento de reactivación inválido',
        ]);
    }

    private function inversionistaConCapital(float $capital): Inversionista
    {
        $inversionista = Inversionista::create(['nombre' => 'INVERSIONISTA PRUEBA', 'tasa_mensual' => 4, 'activo' => true]);
        Aportacion::create([
            'inversionista_id' => $inversionista->id,
            'monto' => $capital,
            'fecha' => '2026-01-01',
            'tipo' => 'Aportacion',
        ]);

        return $inversionista;
    }

    private function idAndTimestamps(Blueprint $table, callable $columns): void
    {
        $table->id();
        $columns();
        $table->timestamps();
    }
}
