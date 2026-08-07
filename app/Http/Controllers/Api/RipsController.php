<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Document;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RipsController extends Controller
{

    private function getCompany()
    {
        $user = Auth::user();
        if (!$user) {
            return [
                'success' => false,
                'message' => 'No se pudo obtener el usuario o la empresa.',
            ];
        }

        return $user->company;
    }

    protected function get_token($company)
    {
        $ripsConfig = $company->ripsConfiguration;
        if (!$ripsConfig) {
            return [
                'success' => false,
                'message' => 'No se encontró la configuración de RIPS para la empresa.',
            ];
        }

        try {
            $client = new Client(['verify' => false]);
            $response = $client->post("{$ripsConfig->url}/api/Auth/LoginSISPRO", [
                'json' => [
                    'persona' => [
                        'identificacion' => [
                            'tipo' => $ripsConfig->typeDocumentIdentification->code_rips,
                            'numero' => $ripsConfig->number_identification,
                        ]
                    ],
                    'clave' => $ripsConfig->password,
                    'nit'   => $company->identification_number,
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
            ]);

            $body = json_decode($response->getBody(), true);

            if (!isset($body['login'], $body['token']) || !$body['login']) {
                return [
                    'success' => false,
                    'message' => 'Error en la autenticación',
                ];
            }

            $token = $body['token'];
            return [
                'success' => true,
                'message' => 'Autenticación exitosa',
                'token' => $token,
            ];

        } catch (\Throwable $e) {
            Log::error("Error autenticando con SISPRO: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al autenticar con SISPRO: ' . $e->getMessage()
            ];;
        }
    }

    private function getUrl($base_url, $has_invoice = false)
    {
        if($has_invoice){
            return $base_url . '/api/PaquetesFevRips/CargarFevRips';
        }
        return $base_url . '/api/PaquetesFevRips/CargarRipsSinFactura';
    }

    private function findXml($company_number, $num_factura)
    {
        // TRIM: prefix es char() y en PostgreSQL llega a CONCAT relleno de espacios (la igualdad nunca matchearia)
        $invoice = Document::where('identification_number', $company_number)->where('response_dian', '!=', null)->where(DB::raw("CONCAT(TRIM(prefix), number)"), $num_factura)->first();
        if (!$invoice) {
            throw new HttpException(404, 'No se encontró la factura en la base de datos.');
        }
        $response_dian = json_decode($invoice->response_dian, true);
        $xml = $response_dian['Envelope']['Body']['SendBillSyncResponse']['SendBillSyncResult']['XmlBase64Bytes'] ?? null;
        if($xml === null){
            throw new HttpException(404, 'No se encontró el XML de la factura.');
        }
        return $xml;
    }

    /**
     * Procesa el envío de RIPS al servicio externo.
     */
    public function processRips(Request $request)
    {
        // $company = $this->getCompany();
        // $hasNumFactura = $request->filled('numFactura');
        // $urlToSend = $this->getUrl($company->ripsConfiguration->url, $hasNumFactura);

        // $xml = $this->findXml($company->identification_number, $request->input('numFactura'));
        // if (!$xml) {
        //     throw new \Exception('No se encontró el XML de la factura.');
        // }

        // dd($xml); // solo para debug
        // funciona
        // $get_token = $this->get_token();
        // if (!$get_token['success']) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => $get_token['message'],
        //     ], 401);
        // }
        // $token = $get_token['token'];

        // envio de rips

        return response()->json([
            'success' => true,
            'message' => 'Rips procesados exitosamente',
        ]);
    }

    /**
     * Procesa el envío de RIPS al servicio externo sin factura asociada.
     *
     * @param array $payload
     * @param Company $company
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function processRipsWithoutInvoice($payload, $company)
    {
        $token = $this->get_token($company);

        if (!$token['success']) {
            return response()->json([
                'success' => false,
                'message' => $token['message'],
            ], 401);
        }

        $urlToSend = $this->getUrl($company->ripsConfiguration->url);

        \Log::info('json: '. json_encode($payload));
        try {
            $client = new Client(['verify' => false]);
            $response = $client->post($urlToSend, [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $token['token'],
                ],
            ]);

            $body = json_decode($response->getBody(), true);
            \Log::info($response->getBody());

            return response()->json($body, 200);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}