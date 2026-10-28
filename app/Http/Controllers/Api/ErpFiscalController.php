<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\DianListado;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;

/** Adaptador durable sobre los controladores existentes; nunca reenvía un resultado incierto. */
class ErpFiscalController extends Controller
{
    private const TIPOS = [
        'factura' => [1, \App\Http\Requests\Api\InvoiceRequest::class, InvoiceController::class],
        'nota_credito' => [4, \App\Http\Requests\Api\CreditNoteRequest::class, CreditNoteController::class],
        'nota_debito' => [5, \App\Http\Requests\Api\DebitNoteRequest::class, DebitNoteController::class],
    ];

    public function identidad(Request $request)
    {
        $c = $request->user()->company;
        return response()->json([
            'version_contrato' => 1, 'nit' => (string) $c->identification_number,
            'ambiente' => (int) $c->type_environment_id === 1 ? 'produccion' : 'habilitacion',
            'software_configurado' => !empty($c->software->identifier),
            'certificado_hasta' => $c->certificate->expiration_date ?? null,
            'idempotencia' => true,
            'resoluciones' => $c->resolutions->map(static fn ($r) => ['tipo_documento' => (int) $r->type_document_id, 'numero_resolucion' => (string) $r->resolution, 'prefijo' => (string) $r->prefix, 'numero_desde' => (string) $r->from, 'numero_hasta' => (string) $r->to, 'vigente_desde' => (string) $r->date_from, 'vigente_hasta' => (string) $r->date_to])->values(),
        ]);
    }

    public function catalogos()
    {
        // Lista fija: el solicitante no puede elegir tablas SQL arbitrarias.
        $tablas = ['tipo_identificacion' => 'type_document_identifications', 'municipio' => 'municipalities', 'tipo_organizacion' => 'type_organizations', 'regimen' => 'type_regimes', 'responsabilidad' => 'type_liabilities', 'unidad' => 'unit_measures', 'identificacion_producto' => 'type_item_identifications', 'tributo' => 'taxes', 'forma_pago' => 'payment_forms', 'medio_pago' => 'payment_methods'];
        $salida = [];
        foreach ($tablas as $clave => $tabla) {
            $salida[$clave] = DB::table($tabla)->select('id', 'name')->orderBy('id')->get();
        }
        return response()->json($salida);
    }

    private function consultaPropia(Request $request)
    {
        return DB::table('erp_operaciones_fiscales')->where('empresa_id', $request->user()->company->id)->where('ambiente', $request->header('X-ERP-Ambiente'));
    }

    private function respuestaOperacion($op)
    {
        $resultado = $this->resultadoActual($op);
        DianListado::sincronizarOperacion($op, $resultado);
        return response()->json(['operacion_id' => $op->id, 'estado' => $op->estado, 'resultado' => $resultado], $op->estado === 'finalizada' ? 200 : 202);
    }

    private function resultadoActual($op): array
    {
        return DianListado::resultadoOperacion($op);
    }

    /** Alineado con el ERP: 00+IsValid, o set de pruebas aceptado (código 2). */
    private function estadoDian(array $resultado): string
    {
        return DianListado::estado($resultado);
    }

    public function operacion(Request $request, string $clave)
    {
        abort_unless(preg_match('/^[A-Za-z0-9-]{16,100}$/D', $clave), 422, 'Clave inválida.');
        $op = $this->consultaPropia($request)->where('clave_idempotencia', $clave)->first();
        abort_unless($op, 404, 'Operación no registrada.');
        return $this->respuestaOperacion($op);
    }

    /** Valida antes de reservar; el marcador procesando se confirma antes de cualquier SOAP. */
    public function emitir(Request $request)
    {
        abort_if(strlen($request->getContent()) > 2 * 1024 * 1024, 413, 'Carga demasiado grande.');
        $entrada = $request->validate(['tipo' => 'required|in:factura,nota_credito,nota_debito', 'conjunto_pruebas' => 'nullable|string|max:100', 'carga' => 'required|array']);
        $clave = (string) $request->header('Idempotency-Key');
        abort_unless(preg_match('/^[A-Za-z0-9-]{16,100}$/D', $clave), 422, 'Clave idempotente requerida.');
        $carga = $entrada['carga'];
        validator($carga, ['prefix' => 'required|string|max:20|regex:/^[A-Za-z0-9]+$/', 'number' => 'required|integer|min:1|max:99999999999999', 'resolution_number' => 'required|string|max:40', 'date' => 'required|date_format:Y-m-d', 'time' => 'required|date_format:H:i:s'])->validate();
        // El puente no admite parámetros de SMTP, rutas, certificados ni plantillas arbitrarias.
        $permitidos = ['type_document_id', 'number', 'prefix', 'resolution_number', 'date', 'time', 'customer', 'invoice_lines', 'credit_note_lines', 'debit_note_lines', 'legal_monetary_totals', 'requested_monetary_totals', 'tax_totals', 'payment_form', 'billing_reference', 'discrepancyresponsecode', 'discrepancyresponsedescription', 'sendmail', 'sendmailtome', 'send_whatsapp', 'send_customer_credentials'];
        abort_if(array_diff(array_keys($carga), $permitidos), 422, 'La carga contiene campos no admitidos por el puente.');
        foreach (['sendmail', 'sendmailtome', 'send_whatsapp', 'send_customer_credentials'] as $campo) { $carga[$campo] = false; }
        $entrada['carga'] = $carga;
        $huella = hash('sha256', $this->canonizar($entrada));
        $existente = $this->consultaPropia($request)->where('clave_idempotencia', $clave)->first();
        if ($existente) { abort_unless(hash_equals($existente->huella, $huella), 409, 'Clave utilizada con otra carga.'); return $this->respuestaOperacion($existente); }
        [$idTipo, $claseRequest, $claseController] = self::TIPOS[$entrada['tipo']];
        abort_unless((int) ($carga['type_document_id'] ?? 0) === $idTipo, 422, 'Tipo documental inconsistente.');
        $hab = $request->header('X-ERP-Ambiente') === 'habilitacion';
        abort_if($hab && empty($entrada['conjunto_pruebas']), 422, 'Se requiere conjunto de pruebas.');
        abort_if(!$hab && !empty($entrada['conjunto_pruebas']), 422, 'Producción no admite conjunto de pruebas.');
        // FormRequest ejecuta las mismas reglas de la versión local de APIDIAN.
        $validado = $this->peticionValidada($claseRequest, $request, $carga);
        $id = (string) Str::uuid();
        try {
            $nueva = DB::transaction(function () use ($request, $id, $clave, $huella, $entrada, $carga) {
                // Serializa reservas de una empresa; no mantiene el bloqueo durante SOAP.
                DB::table('companies')->where('id', $request->user()->company->id)->lockForUpdate()->first();
                $mismaClave = $this->consultaPropia($request)->where('clave_idempotencia', $clave)->first();
                if ($mismaClave) { abort_unless(hash_equals($mismaClave->huella, $huella), 409, 'Clave utilizada con otra carga.'); return false; }
                $anterior = $this->consultaPropia($request)->where('tipo_documento', $entrada['tipo'])->where('prefijo', $carga['prefix'])->where('numero', $carga['number'])->orderByDesc('revision')->first();
                if ($anterior) { abort_unless($this->estadoDian($this->resultadoActual($anterior)) === 'rechazado', 409, 'El número está reservado; sólo un rechazo definitivo admite otra revisión.'); }
                DB::table('erp_operaciones_fiscales')->insert(['id' => $id, 'empresa_id' => $request->user()->company->id, 'ambiente' => $request->header('X-ERP-Ambiente'), 'clave_idempotencia' => $clave, 'huella' => $huella, 'tipo_documento' => $entrada['tipo'], 'prefijo' => $carga['prefix'], 'numero' => $carga['number'], 'revision' => $anterior ? $anterior->revision + 1 : 1, 'estado' => 'procesando', 'carga' => $this->canonizar($entrada), 'creado_en' => now(), 'actualizado_en' => now()]);
                return true;
            });
            if (!$nueva) { return $this->respuestaOperacion($this->consultaPropia($request)->where('clave_idempotencia', $clave)->first()); }
        } catch (QueryException $e) {
            if (!in_array((string) $e->getCode(), ['23000', '23505'], true)) { throw $e; }
            $existente = $this->consultaPropia($request)->where('clave_idempotencia', $clave)->first();
            abort_unless($existente && hash_equals($existente->huella, $huella), 409, 'Identidad fiscal o clave ya reservada.');
            return $this->respuestaOperacion($existente);
        }
        try {
            $controller = app($claseController);
            $resultado = $hab ? $controller->testSetStore($validado, $entrada['conjunto_pruebas']) : $controller->store($validado);
            if ($resultado instanceof \Illuminate\Http\JsonResponse) { $resultado = $resultado->getData(true); }
            abort_unless(is_array($resultado), 502, 'Formato de resultado no reconocido.');
            try { $resultado = $this->adjuntarPdf($request, $resultado); } catch (\Throwable $e) { report($e); }
            DB::table('erp_operaciones_fiscales')->where('id', $id)->update(['estado' => 'finalizada', 'respuesta' => json_encode($resultado, JSON_THROW_ON_ERROR), 'codigo_http' => 200, 'actualizado_en' => now()]);
        } catch (\Throwable $e) {
            // No se reejecuta una llamada que pudo llegar a DIAN. Se conserva para conciliación.
            DB::table('erp_operaciones_fiscales')->where('id', $id)->update(['estado' => 'resultado_desconocido', 'actualizado_en' => now()]);
            report($e);
        }
        return $this->respuestaOperacion($this->consultaPropia($request)->where('id', $id)->first());
    }

    /** Sólo consulta identificadores ya asociados a una operación de la empresa autenticada. */
    public function consultar(Request $request)
    {
        $datos = $request->validate(['clave' => 'required|string|max:100', 'identificador' => 'nullable|string|max:150', 'tipo' => 'required|in:document,zip']);
        $op = $this->consultaPropia($request)->where('clave_idempotencia', $datos['clave'])->first();
        abort_unless($op, 404, 'Operación no encontrada.');
        $resultado = $this->resultadoActual($op);
        $identificador = $datos['identificador'] ?? '';
        $permitidos = [];
        $buscar = function ($nodo) use (&$buscar, &$permitidos) {
            if (!is_array($nodo)) { return; }
            foreach ($nodo as $key => $valor) {
                if (in_array($key, ['cufe', 'XmlDocumentKey', 'ZipKey'], true) && is_string($valor)) { $permitidos[] = $valor; }
                elseif (is_array($valor)) { $buscar($valor); }
            }
        };
        $buscar($resultado);
        abort_unless($identificador && in_array($identificador, $permitidos, true), 409, 'No existe identificador verificable para consultar; requiere conciliación operativa.');
        $validado = $this->peticionValidada(\App\Http\Requests\Api\StatusRequest::class, $request, ['sendmail' => false, 'sendmailtome' => false]);
        $controller = app(StateController::class);
        $respuesta = $datos['tipo'] === 'zip' ? $controller->zip($validado, $identificador) : $controller->document($validado, $identificador);
        if ($respuesta instanceof \Illuminate\Http\JsonResponse) { $respuesta = $respuesta->getData(true); }
        abort_unless(is_array($respuesta), 502, 'Respuesta de consulta no reconocida.');
        // No reemplaza la respuesta original; cada consulta permanece auditable.
        if ($this->estadoDian($resultado) !== 'validado') {
            DB::table('erp_consultas_fiscales')->insert(['operacion_id' => $op->id, 'respuesta' => json_encode($respuesta, JSON_THROW_ON_ERROR), 'creado_en' => now()]);
        }
        $actual = $this->resultadoActual($op);
        DianListado::sincronizarOperacion($op, $actual);
        return response()->json(['resultado' => $this->adjuntarPdf($request, $actual)]);
    }

    private function adjuntarPdf(Request $request, array $resultado): array
    {
        $nombre = $resultado['urlinvoicepdf'] ?? '';
        if (is_string($nombre) && preg_match('/^[A-Za-z0-9_-]+\.pdf$/D', $nombre)) {
            $contenido = \App\Services\StorageService::getAuto('public/' . $request->user()->company->identification_number . '/' . $nombre);
            if (is_string($contenido) && strlen($contenido) <= 8 * 1024 * 1024 && str_starts_with($contenido, '%PDF-')) { $resultado['pdf_base64'] = base64_encode($contenido); }
        }
        return $resultado;
    }

    public function estadoEntrega(Request $request, string $id)
    {
        $fila = DB::table('erp_entregas_fiscales as e')->join('erp_operaciones_fiscales as o', 'o.id', '=', 'e.operacion_id')->where('e.id', $id)->where('o.empresa_id', $request->user()->company->id)->where('o.ambiente', $request->header('X-ERP-Ambiente'))->select('e.id', 'e.estado')->first();
        abort_unless($fila, 404, 'Entrega no encontrada.');
        return response()->json((array) $fila);
    }

    /** Correo separado de emisión. Un SMTP incierto se concilia, no se reenvía automáticamente. */
    public function entregar(Request $request)
    {
        $datos = $request->validate(['id' => 'required|uuid', 'clave_operacion' => 'required|string|max:100']);
        $op = $this->consultaPropia($request)->where('clave_idempotencia', $datos['clave_operacion'])->first();
        abort_unless($op, 404, 'Operación no encontrada.');
        $resultado = $this->adjuntarPdf($request, $this->resultadoActual($op));
        abort_unless($this->estadoDian($resultado) === 'validado', 409, 'Sólo se entrega un documento validado.');
        $entrada = json_decode($op->carga, true);
        $email = $entrada['carga']['customer']['email'] ?? '';
        abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL), 422, 'El adquirente no tiene correo válido.');
        $pdf = base64_decode($resultado['pdf_base64'] ?? '', true); $xml = base64_decode($resultado['attacheddocument'] ?? '', true);
        abort_unless($pdf && $xml, 409, 'Faltan PDF o AttachedDocument para la entrega.');
        $existente = DB::table('erp_entregas_fiscales')->where('id', $datos['id'])->first();
        if ($existente) { abort_unless($existente->operacion_id === $op->id, 409, 'Clave de entrega en conflicto.'); return $this->estadoEntrega($request, $datos['id']); }
        try {
            DB::table('erp_entregas_fiscales')->insert(['id' => $datos['id'], 'operacion_id' => $op->id, 'estado' => 'procesando', 'destinatario' => $email, 'creado_en' => now(), 'actualizado_en' => now()]);
        } catch (QueryException $e) {
            if (!in_array((string) $e->getCode(), ['23000', '23505'], true)) { throw $e; }
            $fila = DB::table('erp_entregas_fiscales')->where('id', $datos['id'])->first();
            abort_unless($fila && $fila->operacion_id === $op->id, 409, 'Clave de entrega en conflicto.');
            return $this->estadoEntrega($request, $datos['id']);
        }
        try {
            $request->user()->applyMailConfig(null);
            $numero = $op->prefijo . $op->numero;
            \Illuminate\Support\Facades\Mail::raw('Adjuntamos el documento electrónico ' . $numero . ' y su evidencia de validación.', function ($mensaje) use ($email, $numero, $pdf, $xml) {
                $mensaje->to($email)->subject('Documento electrónico ' . $numero)->attachData($pdf, $numero . '.pdf', ['mime' => 'application/pdf'])->attachData($xml, $numero . '.xml', ['mime' => 'application/xml']);
            });
            $estado = 'enviada';
        } catch (\Throwable $e) { $estado = 'resultado_desconocido'; report($e); }
        DB::table('erp_entregas_fiscales')->where('id', $datos['id'])->update(['estado' => $estado, 'actualizado_en' => now()]);
        return $this->estadoEntrega($request, $datos['id']);
    }

    private function peticionValidada(string $clase, Request $original, array $datos)
    {
        $validado = $clase::create('/api/erp/v1/operaciones', 'POST', $datos);
        $validado->setContainer(app())->setRedirector(app('redirect'));
        $validado->setUserResolver($original->getUserResolver());
        $validado->setRouteResolver($original->getRouteResolver());
        $validado->headers->set('Accept', 'application/json');
        $validado->validateResolved();
        return $validado;
    }

    private function canonizar(array $datos): string
    {
        $ordenar = static function ($valor) use (&$ordenar) {
            if (!is_array($valor)) { return $valor; }
            if (!array_is_list($valor)) { ksort($valor); }
            foreach ($valor as &$item) { $item = $ordenar($item); } unset($item);
            return $valor;
        };
        return json_encode($ordenar($datos), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
