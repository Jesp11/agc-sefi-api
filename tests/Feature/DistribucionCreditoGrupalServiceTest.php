<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Credito;
use App\Models\Grupo;
use App\Services\DistribucionCreditoGrupalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DistribucionCreditoGrupalServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['credito_grupal_distribuciones', 'cliente_grupo', 'creditos', 'clientes', 'grupos'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('clientes', function (Blueprint $table) {
            $table->string('id_cliente')->primary();
            $table->string('nombre_completo');
            $table->string('curp')->nullable();
            $table->string('clave_elector')->nullable();
            $table->string('telefono')->nullable();
            $table->text('direccion')->nullable();
            $table->timestamps();
        });
        Schema::create('grupos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_grupo');
            $table->timestamps();
        });
        Schema::create('cliente_grupo', function (Blueprint $table) {
            $table->id();
            $table->string('id_cliente');
            $table->unsignedBigInteger('id_grupo');
            $table->timestamps();
        });
        Schema::create('creditos', function (Blueprint $table) {
            $table->id('num_prog');
            $table->string('id_cliente')->nullable();
            $table->unsignedBigInteger('id_grupo')->nullable();
            $table->decimal('monto_otorgado', 12, 2);
            $table->decimal('interes', 12, 2);
            $table->decimal('total', 12, 2);
            $table->decimal('valor_ficha', 12, 2);
            $table->string('tipo_credito');
            $table->timestamps();
        });
        Schema::create('credito_grupal_distribuciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('num_prog');
            $table->string('id_cliente');
            $table->string('nombre_cliente');
            $table->string('curp')->nullable();
            $table->string('clave_elector')->nullable();
            $table->string('telefono')->nullable();
            $table->text('direccion')->nullable();
            $table->decimal('capital', 12, 2);
            $table->decimal('interes', 12, 2);
            $table->decimal('total', 12, 2);
            $table->decimal('valor_ficha', 12, 2);
            $table->unsignedInteger('orden');
            $table->string('folio_documental');
            $table->timestamps();
        });
    }

    public function test_prorrates_documentary_amounts_and_reconciles_every_group_total(): void
    {
        $grupo = Grupo::create(['nombre_grupo' => 'Las Palmas']);
        Cliente::create(['id_cliente' => 'C-01', 'nombre_completo' => 'Ana']);
        Cliente::create(['id_cliente' => 'C-02', 'nombre_completo' => 'Beatriz']);
        $grupo->clientes()->attach(['C-01', 'C-02']);
        $credito = Credito::create([
            'id_grupo' => $grupo->id,
            'monto_otorgado' => 300,
            'interes' => 72.05,
            'total' => 372.05,
            'valor_ficha' => 23.25,
            'tipo_credito' => 'Grupal',
        ]);

        app(DistribucionCreditoGrupalService::class)->guardar($credito, [
            ['id_cliente' => 'C-01', 'capital' => 100.01],
            ['id_cliente' => 'C-02', 'capital' => 199.99],
        ]);

        $distribuciones = $credito->fresh()->distribucionesIntegrantes;
        $this->assertCount(2, $distribuciones);
        $this->assertSame(30000, (int) round($distribuciones->sum('capital') * 100));
        $this->assertSame(7205, (int) round($distribuciones->sum('interes') * 100));
        $this->assertSame(37205, (int) round($distribuciones->sum('total') * 100));
        $this->assertSame(2325, (int) round($distribuciones->sum('valor_ficha') * 100));
        $this->assertSame('1-C-01', $distribuciones->firstWhere('id_cliente', 'C-01')->folio_documental);
    }

    public function test_rejects_missing_or_non_group_members_in_distribution(): void
    {
        $grupo = Grupo::create(['nombre_grupo' => 'Las Palmas']);
        Cliente::create(['id_cliente' => 'C-01', 'nombre_completo' => 'Ana']);
        Cliente::create(['id_cliente' => 'C-02', 'nombre_completo' => 'Beatriz']);
        $grupo->clientes()->attach(['C-01', 'C-02']);
        $credito = Credito::create([
            'id_grupo' => $grupo->id, 'monto_otorgado' => 300, 'interes' => 72,
            'total' => 372, 'valor_ficha' => 23.25, 'tipo_credito' => 'Grupal',
        ]);

        $this->expectException(ValidationException::class);
        app(DistribucionCreditoGrupalService::class)->guardar($credito, [
            ['id_cliente' => 'C-01', 'capital' => 300],
        ]);
    }
}
