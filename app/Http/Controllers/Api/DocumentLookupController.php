<?php

namespace App\Http\Controllers\Api;

use App\Document;
use App\Http\Controllers\Controller;

class DocumentLookupController extends Controller
{
    /**
     * Consulta todos los documentos de la empresa autenticada con un
     * tipo + prefijo + numero exactos. Devuelve tanto los aceptados por
     * DIAN (state_document_id = 1) como los no aceptados/colgados (0),
     * para que el facturador pueda conciliar estados y detectar
     * numeraciones duplicadas sin reenviar el documento.
     */
    public function byPrefixNumber($type_document_id, $prefix, $number)
    {
        $user = auth()->user();
        $company = $user->company;

        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'El usuario autenticado no tiene empresa asociada.',
            ], 422);
        }

        $documents = Document::without(['type_document', 'currency', 'customer_document'])
            ->where('identification_number', $company->identification_number)
            ->where('type_document_id', $type_document_id)
            ->where('prefix', $prefix)
            ->where('number', $number)
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($doc) {
                $client = is_object($doc->client) ? $doc->client : null;

                return [
                    'id' => $doc->id,
                    'type_document_id' => (int) $doc->type_document_id,
                    'prefix' => $doc->prefix,
                    'number' => $doc->number,
                    'state_document_id' => (int) $doc->state_document_id,
                    'cufe' => $doc->cufe,
                    'date_issue' => $doc->date_issue ? $doc->date_issue->format('Y-m-d H:i:s') : null,
                    'total' => $doc->total,
                    'customer' => [
                        'identification_number' => $client->identification_number ?? $doc->customer,
                        'name' => $client->name ?? ($client->business_name ?? null),
                    ],
                    'xml' => $doc->xml,
                    'pdf' => $doc->pdf,
                    'request_api' => json_decode($doc->request_api),
                    'created_at' => $doc->created_at ? $doc->created_at->format('Y-m-d H:i:s') : null,
                ];
            });

        return [
            'success' => true,
            'count' => $documents->count(),
            'documents' => $documents,
        ];
    }
}
