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

Route::group([
    'middleware' => 'api',
    'prefix' => 'auth'
], function ($router) {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:api');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('auth:api');
    Route::post('me', [AuthController::class, 'me'])->middleware('auth:api');
});

Route::middleware('auth:api')->group(function () {
    Route::apiResource('clientes', ClienteController::class);
    Route::apiResource('asesores', AsesorController::class);
    Route::apiResource('creditos', CreditoController::class);
    Route::apiResource('referencias', ReferenciaController::class);
    Route::apiResource('avales', AvalController::class);
    Route::apiResource('grupos', GrupoController::class);
    Route::post('grupos/{id}/agregar-cliente', [GrupoController::class, 'agregarCliente']);
    Route::post('grupos/{id}/quitar-cliente', [GrupoController::class, 'quitarCliente']);
    
    // Rutas de simulación
    Route::post('simular/individual', [\App\Http\Controllers\SimulationController::class, 'simulateIndividual']);
    Route::post('simular/grupal', [\App\Http\Controllers\SimulationController::class, 'simulateGroup']);
    Route::get('simular/catalogo/individual', [\App\Http\Controllers\SimulationController::class, 'getCatalogIndividual']);
    Route::get('simular/catalogo/grupal', [\App\Http\Controllers\SimulationController::class, 'getCatalogGroup']);



    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});
