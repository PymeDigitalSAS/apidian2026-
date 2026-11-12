<?php

namespace Tests\Unit;

use App\Support\PasoProduccion;
use PHPUnit\Framework\TestCase;

class PasoProduccionTest extends TestCase
{
    public function test_un_set_ya_aceptado_no_es_un_error_de_zipkey()
    {
        $set = '745c4e57-fe10-49b5-a5a1-b96950115899';
        $respuesta = [
            'message' => 'Consulta generada con éxito',
            'ResponseDian' => [
                'Envelope' => [
                    'Body' => [
                        'GetStatusZipResponse' => [
                            'GetStatusZipResult' => [
                                'DianResponse' => [
                                    'IsValid' => 'false',
                                    'StatusCode' => '2',
                                    'StatusDescription' => "Set de prueba con identificador {$set} se encuentra Aceptado.",
                                    'StatusMessage' => ['_attributes' => ['nil' => 'true']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $decision = PasoProduccion::consultaZip($respuesta);

        $this->assertTrue($decision['already_accepted']);
        $this->assertArrayNotHasKey('error', $decision);
        $this->assertStringNotContainsString('_attributes', json_encode($decision));
    }

    public function test_el_envio_con_zipkey_nulo_y_set_aceptado_continua()
    {
        $respuesta = [
            'message' => 'Set de prueba con identificador 745c4e57-fe10-49b5-a5a1-b96950115899 se encuentra Aceptado.',
            'ResponseDian' => [
                'Envelope' => [
                    'Body' => [
                        'SendTestSetAsyncResponse' => [
                            'SendTestSetAsyncResult' => [
                                'ZipKey' => ['_attributes' => ['nil' => 'true']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $decision = PasoProduccion::envio($respuesta);

        $this->assertTrue($decision['already_accepted']);
        $this->assertArrayNotHasKey('zipkey', $decision);
    }

    public function test_un_zipkey_real_se_conserva()
    {
        $decision = PasoProduccion::envio([
            'ResponseDian' => [
                'Envelope' => [
                    'Body' => [
                        'SendTestSetAsyncResponse' => [
                            'SendTestSetAsyncResult' => [
                                'ZipKey' => 'abc123-zip',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('abc123-zip', $decision['zipkey']);
    }

    public function test_un_rechazo_real_no_incluye_el_nil_del_soap()
    {
        $decision = PasoProduccion::consultaZip([
            'ResponseDian' => [
                'Envelope' => [
                    'Body' => [
                        'GetStatusZipResponse' => [
                            'GetStatusZipResult' => [
                                'DianResponse' => [
                                    'IsValid' => false,
                                    'StatusDescription' => 'Documento con errores en campos mandatorios.',
                                    'StatusMessage' => ['_attributes' => ['nil' => 'true']],
                                    'ErrorMessage' => ['string' => ['Regla FAJ43b']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            'Documento con errores en campos mandatorios.<br>Regla FAJ43b',
            $decision['error']
        );
        $this->assertStringNotContainsString('_attributes', $decision['error']);
    }

    public function test_un_documento_en_validacion_pide_esperar()
    {
        $decision = PasoProduccion::consultaZip([
            'ResponseDian' => [
                'Envelope' => [
                    'Body' => [
                        'GetStatusZipResponse' => [
                            'GetStatusZipResult' => [
                                'DianResponse' => [
                                    'IsValid' => 'false',
                                    'StatusDescription' => 'El documento se encuentra en proceso de validación.',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString('espera unos minutos', $decision['error']);
    }
}
