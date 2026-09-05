<?php

namespace Tests\Feature;

use App\Models\Asesor;
use App\Models\Cliente;
use App\Models\Credito;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PagosAtrasadosAuthorizationTest extends TestCase
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

    public function test_advisors_are_forced_to_their_own_portfolio_while_authorized_global_roles_see_all(): void
    {
        $advisorA = Asesor::create(['nombre_asesor' => 'Ana']);
        $advisorB = Asesor::create(['nombre_asesor' => 'Beto']);
        $this->credito($advisorA, 'CLI-001', 'Cliente Ana');
        $this->credito($advisorB, 'CLI-002', 'Cliente Beto');

        $advisor = $this->user('asesor', $advisorA->id);
        $this->actingAs($advisor, 'api')
            ->getJson("/api/reportes/pagos-atrasados?fecha_inicio=2026-08-01&fecha_fin=2026-08-08&id_asesor={$advisorB->id}")
            ->assertOk()
            ->assertJsonPath('resumen.creditos', 1)
            ->assertJsonPath('por_asesor.0.id_asesor', $advisorA->id);

        foreach (['admin', 'Contabilidad', 'Gestor de Cobranza'] as $roleName) {
            $this->actingAs($this->user($roleName), 'api')
                ->getJson('/api/reportes/pagos-atrasados?fecha_inicio=2026-08-01&fecha_fin=2026-08-08')
                ->assertOk()
                ->assertJsonPath('resumen.creditos', 2);
        }
    }

    public function test_rejects_invalid_ranges_and_roles_without_access(): void
    {
        $this->actingAs($this->user('Sin acceso'), 'api')
            ->getJson('/api/reportes/pagos-atrasados?fecha_inicio=2026-08-08&fecha_fin=2026-08-01')
            ->assertUnprocessable();

        $this->actingAs($this->user('Sin acceso'), 'api')
            ->getJson('/api/reportes/pagos-atrasados?fecha_inicio=2026-08-01&fecha_fin=2026-08-08')
            ->assertForbidden();
    }

    private function user(string $roleName, ?int $asesorId = null): User
    {
        $role = Role::firstOrCreate(['nombre' => $roleName]);

        return User::create([
            'name' => "Usuario {$roleName}",
            'email' => strtolower(str_replace(' ', '.', $roleName)) . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'role_id' => $role->id,
            'id_asesor' => $asesorId,
        ]);
    }

    private function credito(Asesor $asesor, string $clienteId, string $nombreCliente): void
    {
        Cliente::create(['id_cliente' => $clienteId, 'nombre_completo' => $nombreCliente]);
        Credito::create([
            'id_cliente' => $clienteId,
            'id_asesor' => $asesor->id,
            'fecha_otorgacion' => '2026-07-25',
            'fecha_primer_pago' => '2026-08-01',
            'ciclo' => 1,
            'monto_otorgado' => 100,
            'interes' => 0,
            'total' => 100,
            'saldo_pendiente' => 100,
            'plazos' => 1,
            'valor_ficha' => 100,
            'dias_pago' => 'SABADO',
            'tipo_credito' => 'Individual',
            'estado' => 'Activo',
        ]);
    }

    private function createSchema(): void
    {
        foreach (['pagos', 'creditos', 'clientes', 'asesores', 'users', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('roles', function (Blueprint $table) { $table->id(); $table->string('nombre'); $table->timestamps(); });
        Schema::create('asesores', function (Blueprint $table) { $table->id(); $table->string('nombre_asesor'); $table->timestamps(); });
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('email')->unique(); $table->string('password'); $table->unsignedBigInteger('role_id')->nullable(); $table->unsignedBigInteger('id_asesor')->nullable(); $table->timestamps();
        });
        Schema::create('clientes', function (Blueprint $table) { $table->string('id_cliente')->primary(); $table->string('nombre_completo'); $table->timestamps(); });
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
