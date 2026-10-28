<?php
namespace App\Http\Middleware;

use Closure;

/** Compara emisor y ambiente en todas las operaciones, no sólo durante el alta. */
class ErpContextoFiscal
{
    public function handle($request, Closure $next)
    {
        abort_unless(config('erp.enabled'), 503, 'Puente ERP desactivado.');
        $empresa = $request->user()->company;
        abort_unless($empresa && in_array((string) $empresa->identification_number, config('erp.allowed_nits', []), true), 403, 'Empresa no habilitada para el puente.');
        $ambiente = (int) $empresa->type_environment_id === 1 ? 'produccion' : ((int) $empresa->type_environment_id === 2 ? 'habilitacion' : 'desconocido');
        abort_unless(hash_equals((string) $empresa->identification_number, (string) $request->header('X-ERP-NIT')) && hash_equals($ambiente, (string) $request->header('X-ERP-Ambiente')), 403, 'Emisor o ambiente no coincide.');
        abort_if($ambiente === 'desconocido', 409, 'Ambiente no reconocido.');
        if ($request->isMethod('post') && $request->is('api/erp/v1/operaciones') && $ambiente === 'produccion') {
            abort_unless(config('erp.production_enabled'), 403, 'Producción desactivada en el puente.');
        }
        return $next($request);
    }
}
