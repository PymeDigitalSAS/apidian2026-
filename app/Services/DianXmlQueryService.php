<?php

namespace App\Services;

use ubl21dian\Templates\SOAP\GetXmlByDocumentKey;

/**
 * Consulta stateless de XMLs por CUFE contra la DIAN (GetXmlByDocumentKey),
 * usando un certificado .p12 + contraseña. NO requiere empresa.
 *
 * Reutilizado por:
 *  - XmlByCufeController (endpoint directo /api/ubl2.1/xml/by-cufes)
 *  - CufesReportController (orquestador token -> cufes -> xml -> reporte)
 */
class DianXmlQueryService
{
    /** URL del servicio DIAN por ambiente (1=Producción, 2=Habilitación). */
    public static function urlForAmbiente($ambiente): string
    {
        return (string) $ambiente === '1'
            ? 'https://vpfe.dian.gov.co/WcfDianCustomerServices.svc'
            : 'https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc';
    }

    /**
     * Consulta un lote de CUFEs y devuelve el XML crudo de cada uno.
     *
     * @return array{
     *   ok: bool,
     *   error: ?string,   // error global (cert inválido, etc.)
     *   results: array<int, array{cufe:string, success:bool, xml?:string, error?:string}>
     * }
     */
    public function queryByCufes(string $certBase64, string $password, string $dianUrl, array $cufes): array
    {
        $binary = base64_decode($certBase64, true);
        if ($binary === false || $binary === '') {
            return ['ok' => false, 'error' => 'El certificado no es base64 válido.', 'results' => []];
        }

        // Validación temprana (falla claro si la contraseña o el legacy de OpenSSL fallan)
        if (!@openssl_pkcs12_read($binary, $certs, $password)) {
            return [
                'ok' => false,
                'error' => 'No se pudo abrir el certificado con la contraseña indicada '
                    . '(verifique la contraseña y el proveedor legacy de OpenSSL para archivos .p12).',
                'results' => [],
            ];
        }

        // La librería lee el certificado por ruta -> archivo temporal
        $tmpCert = tempnam(sys_get_temp_dir(), 'dianp12_');
        file_put_contents($tmpCert, $binary);

        $parser = new UblDocumentParser();
        $results = [];

        try {
            foreach ($cufes as $cufe) {
                $cufe = trim((string) $cufe);
                if ($cufe === '') {
                    continue;
                }

                try {
                    $g = new GetXmlByDocumentKey($tmpCert, $password, $dianUrl);
                    $g->trackId = $cufe;
                    $r = $g->signToSend()->getResponseToObject();

                    $res = $r->Envelope->Body->GetXmlByDocumentKeyResponse->GetXmlByDocumentKeyResult;

                    if (isset($res->Code) && (string) $res->Code === '100') {
                        // XmlBytesBase64 = base64 del XML (posible AttachedDocument)
                        $xml = base64_decode(json_encode($res->XmlBytesBase64));
                        // Des-envolver AttachedDocument -> Invoice/CreditNote/DebitNote
                        $xml = $parser->unwrapAttachedDocument($xml);

                        $results[] = ['cufe' => $cufe, 'success' => true, 'xml' => $xml];
                    } else {
                        $results[] = [
                            'cufe' => $cufe,
                            'success' => false,
                            'error' => (string) ($res->Message ?? 'La DIAN no devolvió el documento (Code != 100).'),
                        ];
                    }
                } catch (\Throwable $e) {
                    $results[] = ['cufe' => $cufe, 'success' => false, 'error' => $e->getMessage()];
                }
            }
        } finally {
            @unlink($tmpCert);
        }

        return ['ok' => true, 'error' => null, 'results' => $results];
    }
}
