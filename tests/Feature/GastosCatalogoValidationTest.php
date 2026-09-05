<?php

namespace Tests\Feature;

use App\Http\Controllers\CatalogoGastoController;
use App\Http\Controllers\GastoController;
use App\Models\CatalogoGasto;
use App\Models\GastoOperativo;
use App\Services\CapitalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class GastosCatalogoValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('catalogo_gastos');
        Schema::create('catalogo_gastos', function (Blueprint $table) {
            $table->id();
            $table->string('concepto')->nullable();
            $table->string('categoria')->nullable();
            $table->decimal('monto_sugerido', 12, 2)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_catalogo_requires_a_category_name(): void
    {
        $controller = new CatalogoGastoController;

        $this->expectException(ValidationException::class);

        $controller->store(Request::create('/catalogo-gastos', 'POST', [
            'concepto' => 'Este campo ya no forma parte del catálogo',
        ]));
    }

    public function test_catalogo_accepts_a_category_without_a_concept_or_suggested_amount(): void
    {
        $controller = new CatalogoGastoController;

        $response = $controller->store(Request::create('/catalogo-gastos', 'POST', [
            'categoria' => 'Mantenimiento de vehículo',
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertDatabaseHas('catalogo_gastos', [
            'concepto' => null,
            'categoria' => 'Mantenimiento de vehículo',
            'activo' => true,
        ]);
    }

    public function test_registering_a_gasto_requires_an_active_catalog_entry(): void
    {
        $controller = new GastoController;
        $service = Mockery::mock(CapitalService::class);

        $this->expectException(ValidationException::class);

        $controller->store(Request::create('/gastos', 'POST', [
            'monto' => 250,
            'fecha' => '2026-09-05',
            'cuenta' => 'Efectivo',
        ]), $service);
    }

    public function test_registering_a_gasto_uses_the_catalog_concept_and_category(): void
    {
        $catalogo = CatalogoGasto::create([
            'categoria' => 'Servicios generales',
            'activo' => true,
        ]);
        $controller = new GastoController;
        $service = Mockery::mock(CapitalService::class);

        $service->shouldReceive('registrarGasto')->once()->withArgs(function (array $data) use ($catalogo) {
            $this->assertSame($catalogo->id, $data['catalogo_gasto_id']);
            $this->assertSame('Compra de papelería', $data['concepto']);
            $this->assertSame('Servicios generales', $data['categoria']);
            $this->assertSame(475, $data['monto']);

            return true;
        })->andReturn(new GastoOperativo);

        $response = $controller->store(Request::create('/gastos', 'POST', [
            'catalogo_gasto_id' => $catalogo->id,
            'concepto' => 'Compra de papelería',
            'categoria' => 'Texto enviado por el cliente que debe ignorarse',
            'monto' => 475,
            'fecha' => '2026-09-05',
            'cuenta' => 'Bancomer',
        ]), $service);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function test_editing_preserves_a_reference_to_an_inactive_historical_catalog_entry(): void
    {
        $catalogo = CatalogoGasto::create([
            'categoria' => 'Compra histórica',
            'activo' => false,
        ]);
        $gasto = new GastoOperativo([
            'catalogo_gasto_id' => $catalogo->id,
            'concepto' => 'Compra histórica',
        ]);
        $controller = new GastoController;
        $service = Mockery::mock(CapitalService::class);

        $service->shouldReceive('actualizarGasto')->once()->withArgs(function (GastoOperativo $recibido, array $data) use ($gasto, $catalogo) {
            $this->assertSame($gasto, $recibido);
            $this->assertSame($catalogo->id, $data['catalogo_gasto_id']);
            $this->assertSame('Compra histórica corregida', $data['concepto']);
            $this->assertSame('Compra histórica', $data['categoria']);

            return true;
        })->andReturn($gasto);

        $response = $controller->update(Request::create('/gastos/1', 'PUT', [
            'catalogo_gasto_id' => $catalogo->id,
            'concepto' => 'Compra histórica corregida',
            'monto' => 180,
            'fecha' => '2026-09-05',
            'cuenta' => 'Efectivo',
        ]), $gasto, $service);

        $this->assertSame(200, $response->getStatusCode());
    }
}
