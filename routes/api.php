<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\AsesorController;
use App\Http\Controllers\CreditoController;
use App\Http\Controllers\ReferenciaController;
use App\Http\Controllers\AvalController;
use App\Http\Controllers\GrupoController;
use App\Http\Controllers\PagoController;
use App\Http\Controllers\CarteraController;
use App\Http\Controllers\RefinanciamientoController;
use App\Http\Controllers\HistorialController;
use App\Http\Controllers\DocumentoClienteController;
use App\Http\Controllers\InversionistaController;
use App\Http\Controllers\CapitalController;
use App\Http\Controllers\GastoController;
use App\Http\Controllers\CatalogoGastoController;
use App\Http\Controllers\FlujoCajaController;
use App\Http\Controllers\EmpleadoController;
use App\Http\Controllers\NominaController;
use App\Http\Controllers\AhorroController;
use App\Http\Controllers\AhorroPersonalController;
use App\Http\Controllers\SocioController;
use App\Http\Controllers\AhorroSocioController;
use App\Http\Controllers\ReporteController;

Route::group([
    'middleware' => 'api',
    'prefix' => 'auth'
], function ($router) {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:api');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('auth:api');
    Route::post('me', [AuthController::class, 'me'])->middleware('auth:api');
    Route::put('profile', [AuthController::class, 'updateProfile'])->middleware('auth:api');
});

Route::middleware('auth:api')->group(function () {
    Route::get('clientes/export', [ClienteController::class, 'export']);
    Route::post('clientes/import', [ClienteController::class, 'import']);
    Route::apiResource('clientes', ClienteController::class);
    Route::get('asesores/export', [AsesorController::class, 'export']);
    Route::post('asesores/import', [AsesorController::class, 'import']);
    Route::post('asesores/{id}/acceso', [AsesorController::class, 'crearAcceso'])->middleware('role:admin');
    Route::put('asesores/{id}/acceso', [AsesorController::class, 'actualizarAcceso'])->middleware('role:admin');
    Route::apiResource('asesores', AsesorController::class);
    // Lectura de créditos (asesor y admin)
    Route::get('creditos', [CreditoController::class, 'index']);
    Route::get('creditos/{credito}', [CreditoController::class, 'show']);
    // Escritura de cartera solo admin
    Route::post('creditos', [CreditoController::class, 'store'])->middleware('role:admin');
    Route::put('creditos/{credito}', [CreditoController::class, 'update'])->middleware('role:admin');
    Route::patch('creditos/{credito}', [CreditoController::class, 'update'])->middleware('role:admin');
    Route::delete('creditos/{credito}', [CreditoController::class, 'destroy'])->middleware('role:admin');

    Route::apiResource('referencias', ReferenciaController::class);
    Route::apiResource('avales', AvalController::class);
    Route::apiResource('grupos', GrupoController::class);
    Route::post('grupos/{id}/agregar-cliente', [GrupoController::class, 'agregarCliente']);
    Route::post('grupos/{id}/quitar-cliente', [GrupoController::class, 'quitarCliente']);

    // Pagos: el asesor puede registrar cobros; lectura libre autenticada
    Route::get('creditos/{num_prog}/pagos', [PagoController::class, 'index']);
    Route::post('creditos/{num_prog}/pagos', [PagoController::class, 'store']);
    Route::get('creditos/{num_prog}/mora', [CarteraController::class, 'moraDetalle']);
    Route::post('creditos/{num_prog}/refinanciar', [RefinanciamientoController::class, 'store'])
        ->middleware('role:admin');

    // Cartera (lectura para asesor; cambios de estado solo admin)
    Route::get('cartera/activa', [CarteraController::class, 'activa']);
    Route::get('cartera/mora', [CarteraController::class, 'mora']);
    Route::get('cartera/cerrados', [CarteraController::class, 'cerrados']);
    Route::get('cartera/cobros-del-dia', [CarteraController::class, 'cobrosDelDia']);
    // Asesor y admin: mover a mora o cerrar sin renovación
    Route::post('creditos/{num_prog}/enviar-mora', [CarteraController::class, 'enviarMora']);
    Route::post('creditos/{num_prog}/cerrar-cartera', [CarteraController::class, 'cerrar']);
    // Solo admin: reactivar
    Route::post('creditos/{num_prog}/reactivar-cartera', [CarteraController::class, 'reactivarCredito'])
        ->middleware('role:admin');

    // Historial y reactivación
    Route::get('clientes/{id}/historial', [HistorialController::class, 'cliente']);
    Route::post('clientes/{id}/reactivar', [HistorialController::class, 'reactivar']);
    Route::get('grupos/{id}/historial', [HistorialController::class, 'grupo']);

    // Documentos KYC
    Route::get('clientes/{id}/documentos', [DocumentoClienteController::class, 'index']);
    Route::post('clientes/{id}/documentos', [DocumentoClienteController::class, 'store']);
    Route::delete('clientes/{id}/documentos/{docId}', [DocumentoClienteController::class, 'destroy']);

    // Contabilidad
    Route::get('inversionistas', [InversionistaController::class, 'index']);
    Route::post('inversionistas', [InversionistaController::class, 'store']);
    Route::put('inversionistas/{id}', [InversionistaController::class, 'update']);
    Route::post('inversionistas/{id}/aportaciones', [InversionistaController::class, 'aportacion']);
    Route::get('capital', [CapitalController::class, 'index']);
    Route::get('gastos', [GastoController::class, 'index']);
    Route::post('gastos', [GastoController::class, 'store']);
    Route::get('catalogo-gastos', [CatalogoGastoController::class, 'index']);
    Route::post('catalogo-gastos', [CatalogoGastoController::class, 'store']);
    Route::put('catalogo-gastos/{id}', [CatalogoGastoController::class, 'update']);
    Route::get('flujo-caja', [FlujoCajaController::class, 'index']);
    Route::get('flujo-caja/resumen', [FlujoCajaController::class, 'resumen']);
    Route::get('flujo-caja/cuentas', [FlujoCajaController::class, 'cuentas']);
    Route::post('flujo-caja', [FlujoCajaController::class, 'store']);
    Route::get('empleados', [EmpleadoController::class, 'index']);
    Route::post('empleados', [EmpleadoController::class, 'store']);
    Route::put('empleados/{id}', [EmpleadoController::class, 'update']);
    Route::get('nomina', [NominaController::class, 'index']);
    Route::post('nomina', [NominaController::class, 'store']);
    Route::get('ahorros', [AhorroController::class, 'index']);
    Route::post('ahorros/{empleadoId}/retiro', [AhorroController::class, 'retiro']);
    Route::post('ahorros-personal/import', [AhorroPersonalController::class, 'import']);
    Route::get('ahorros-personal', [AhorroPersonalController::class, 'index']);
    Route::get('ahorros-personal/resumen', [AhorroPersonalController::class, 'resumen']);
    Route::post('ahorros-personal/{asesorId}/ingreso', [AhorroPersonalController::class, 'ingreso']);
    Route::post('ahorros-personal/{asesorId}/retiro', [AhorroPersonalController::class, 'retiro']);
    Route::get('socios', [SocioController::class, 'index']);
    Route::post('socios', [SocioController::class, 'store']);
    Route::put('socios/{id}', [SocioController::class, 'update']);
    Route::get('ahorros-socios', [AhorroSocioController::class, 'index']);
    Route::get('ahorros-socios/resumen', [AhorroSocioController::class, 'resumen']);
    Route::post('ahorros-socios/{socioId}/ingreso', [AhorroSocioController::class, 'ingreso']);
    Route::post('ahorros-socios/{socioId}/retiro', [AhorroSocioController::class, 'retiro']);

    // Reportes
    Route::get('reportes/diario', [ReporteController::class, 'diario']);
    Route::post('reportes/diario/recibir', [ReporteController::class, 'recibirAsesor'])
        ->middleware('role:admin');
    Route::get('reportes/cartera', [ReporteController::class, 'cartera']);
    Route::get('reportes/asesor/diario', [ReporteController::class, 'asesorDiario']);
    Route::get('reportes/asesor/mora', [ReporteController::class, 'asesorMora']);
    Route::get('reportes/asesor/por-cerrar', [ReporteController::class, 'asesorPorCerrar']);
    Route::get('reportes/semanal', [ReporteController::class, 'semanal']);
    Route::get('reportes/inversionistas', [ReporteController::class, 'inversionistas']);
    Route::get('reportes/ahorros', [ReporteController::class, 'ahorros']);
    Route::get('reportes/ahorros-personal', [ReporteController::class, 'ahorrosPersonal']);
    Route::get('reportes/ahorros-socios', [ReporteController::class, 'ahorrosSocios']);
    Route::get('reportes/comparativas', [ReporteController::class, 'comparativas']);

    // Simulación
    Route::post('simular/individual', [\App\Http\Controllers\SimulationController::class, 'simulateIndividual']);
    Route::post('simular/grupal', [\App\Http\Controllers\SimulationController::class, 'simulateGroup']);
    Route::get('simular/catalogo/individual', [\App\Http\Controllers\SimulationController::class, 'getCatalogIndividual']);
    Route::get('simular/catalogo/grupal', [\App\Http\Controllers\SimulationController::class, 'getCatalogGroup']);

    Route::get('/user', function (Request $request) {
        return $request->user()->load('role', 'asesor');
    });
});
