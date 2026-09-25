<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\ConfirmacionMovimiento;
use App\Models\Credito;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreditoEdicionMovimientoPendienteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    public static function estadosEnProceso(): array
    {
        return array_map(fn (string $estado) => [$estado], ConfirmacionMovimiento::ESTADOS_EN_PROCESO);
    }

    #[DataProvider('estadosEnProceso')]
    public function test_no_permite_editar_un_credito_con_movimiento_sin_confirmar_ni_cancelar(string $estado): void
    {
        $credito = $this->credito();
        $this->confirmacion($credito, $estado);

        $this->actingAs($this->admin(), 'api')
            ->putJson("/api/creditos/{$credito->num_prog}", ['monto_otorgado' => 8000])
            ->assertStatus(422)
            ->assertJsonPath('movimiento_en_proceso.estado', $estado)
            ->assertJsonFragment(['message' => "No se puede editar el crédito #{$credito->num_prog}: su movimiento de desembolso está "
                .(new ConfirmacionMovimiento(['estado' => $estado]))->descripcionEstado()
                .' en Movimientos. Confírmalo o cancélalo antes de editar el crédito.']);

        $this->assertSame(5000.0, (float) $credito->fresh()->monto_otorgado);
    }

    public function test_tambien_bloquea_la_sincronizacion_de_renovacion(): void
    {
        $credito = $this->credito();
        $this->confirmacion($credito, 'Pendiente');

        $this->actingAs($this->admin(), 'api')
            ->putJson("/api/creditos/{$credito->num_prog}", ['sincronizar_refinanciamiento' => true])
            ->assertStatus(422)
            ->assertJsonPath('movimiento_en_proceso.estado', 'Pendiente');
    }

    public function test_movimientos_confirmados_o_cancelados_no_bloquean_la_edicion(): void
    {
        $credito = $this->credito();
        foreach (['Confirmado', 'Reprogramado', 'Cancelado'] as $i => $estado) {
            $this->confirmacion($credito, $estado, "DESEMBOLSO-{$credito->num_prog}-{$i}");
        }

        $this->assertNull($credito->movimientoEnProceso());
    }

    private function credito(): Credito
    {
        Cliente::create(['id_cliente' => 'CLI-001', 'nombre_completo' => 'Cliente prueba']);

        return Credito::create([
            'id_cliente' => 'CLI-001', 'id_asesor' => 1, 'fecha_otorgacion' => '2026-09-24',
            'monto_otorgado' => 5000, 'estado' => 'PendienteDesembolso',
        ]);
    }

    private function confirmacion(Credito $credito, string $estado, ?string $referencia = null): ConfirmacionMovimiento
    {
        return ConfirmacionMovimiento::create([
            'fecha' => '2026-09-24', 'motivo' => "DESEMBOLSO CRÉDITO #{$credito->num_prog}", 'monto' => 4900,
            'categoria' => 'Desembolso', 'cuenta' => 'Efectivo', 'num_prog' => $credito->num_prog,
            'referencia' => $referencia ?? "DESEMBOLSO-{$credito->num_prog}", 'estado' => $estado,
        ]);
    }

    private function admin(): User
    {
        $role = Role::firstOrCreate(['nombre' => 'admin']);

        return User::create([
            'name' => 'Admin', 'email' => uniqid('admin').'@test.local',
            'password' => Hash::make('secret'), 'role_id' => $role->id,
        ]);
    }

    private function createSchema(): void
    {
        foreach (['confirmaciones_movimientos', 'creditos', 'clientes', 'users', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('roles', function (Blueprint $table) { $table->id(); $table->string('nombre'); $table->timestamps(); });
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('email')->unique(); $table->string('password'); $table->unsignedBigInteger('role_id')->nullable(); $table->unsignedBigInteger('id_asesor')->nullable(); $table->timestamps();
        });
        Schema::create('clientes', function (Blueprint $table) { $table->string('id_cliente')->primary(); $table->string('nombre_completo'); $table->timestamps(); });
        Schema::create('creditos', function (Blueprint $table) {
            $table->id('num_prog'); $table->string('id_cliente')->nullable(); $table->unsignedBigInteger('id_grupo')->nullable(); $table->unsignedBigInteger('id_asesor')->nullable();
            $table->date('fecha_otorgacion'); $table->decimal('monto_otorgado', 12, 2); $table->string('tipo_credito')->default('Individual'); $table->string('estado')->default('Activo'); $table->timestamps();
        });
        Schema::create('confirmaciones_movimientos', function (Blueprint $table) {
            $table->id(); $table->date('fecha'); $table->unsignedBigInteger('id_asesor')->nullable(); $table->text('motivo');
            $table->decimal('monto', 14, 2); $table->string('categoria')->nullable(); $table->string('cuenta')->nullable();
            $table->unsignedBigInteger('num_prog')->nullable(); $table->string('referencia')->nullable()->unique();
            $table->string('estado')->default('Pendiente'); $table->unsignedBigInteger('movimiento_caja_id')->nullable(); $table->unsignedBigInteger('movimiento_reintegro_id')->nullable();
            $table->unsignedBigInteger('solicitado_por')->nullable(); $table->unsignedBigInteger('confirmado_por')->nullable(); $table->timestamp('confirmado_at')->nullable();
            $table->timestamps();
        });
    }
}
