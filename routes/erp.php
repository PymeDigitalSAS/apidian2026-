<?php
/** Contrato ERP v1: rutas separadas del API legado y siempre autenticadas. */
\Illuminate\Support\Facades\Route::prefix('erp/v1')
    ->middleware(['auth:api', 'storage.context', \App\Http\Middleware\ErpContextoFiscal::class, 'throttle:60,1'])
    ->group(function () {
        \Illuminate\Support\Facades\Route::get('identidad', [\App\Http\Controllers\Api\ErpFiscalController::class, 'identidad']);
        \Illuminate\Support\Facades\Route::get('catalogos', [\App\Http\Controllers\Api\ErpFiscalController::class, 'catalogos']);
        \Illuminate\Support\Facades\Route::post('operaciones', [\App\Http\Controllers\Api\ErpFiscalController::class, 'emitir']);
        \Illuminate\Support\Facades\Route::get('operaciones/{clave}', [\App\Http\Controllers\Api\ErpFiscalController::class, 'operacion']);
        \Illuminate\Support\Facades\Route::post('consultar', [\App\Http\Controllers\Api\ErpFiscalController::class, 'consultar']);
        \Illuminate\Support\Facades\Route::post('entregas', [\App\Http\Controllers\Api\ErpFiscalController::class, 'entregar']);
        \Illuminate\Support\Facades\Route::get('entregas/{id}', [\App\Http\Controllers\Api\ErpFiscalController::class, 'estadoEntrega']);
    });
