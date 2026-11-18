<?php

namespace Tests\Unit;

use App\Company;
use App\Support\RepresentacionFactura;
use Illuminate\Http\Request;
use Tests\TestCase;

class RepresentacionFacturaTest extends TestCase
{
    public function test_completa_la_factura_con_los_datos_marcados()
    {
        $empresa = new Company();
        $empresa->invoice_graphic = [
            'actividad' => ['on' => true, 'value' => '9603'],
            'ica' => ['on' => true, 'value' => '10x1000'],
            'ica_anual' => ['on' => true],
            'letras' => ['on' => true],
        ];

        $request = Request::create('/', 'POST', [
            'legal_monetary_totals' => ['payable_amount' => 119000],
        ]);

        $html = RepresentacionFactura::aplicar($request, $empresa, 'invoice', 1, []);

        $this->assertSame('9603', $request->input('actividadeconomica'));
        $this->assertSame('10x1000', $request->input('tarifaica'));
        $this->assertTrue((bool) $request->input('presentacion_ica_anual'));
        $this->assertSame('', $html);
    }

    public function test_agrega_al_final_lo_que_la_plantilla_no_tiene()
    {
        $empresa = new Company();
        $empresa->invoice_graphic = RepresentacionFactura::normalizar([
            'actividad' => ['on' => true, 'value' => ''],
            'ica' => ['on' => true, 'value' => '10x1000'],
            'ica_anual' => ['on' => true],
            'letras' => ['on' => true],
        ]);

        $request = Request::create('/', 'POST', [
            'legal_monetary_totals' => ['payable_amount' => 1000],
        ]);

        $html = RepresentacionFactura::aplicar($request, $empresa, 'invoice', 10, []);

        $this->assertSame('', $html);
        $this->assertSame('9603', $request->input('actividadeconomica'));
        $this->assertTrue((bool) $request->input('valor_en_letras'));
    }

    public function test_no_pisa_un_dato_que_ya_viene_en_la_factura()
    {
        $empresa = new Company();
        $empresa->invoice_graphic = [
            'actividad' => ['on' => true, 'value' => '9603'],
            'ica' => ['on' => false, 'value' => '10x1000'],
            'ica_anual' => ['on' => false],
            'letras' => ['on' => false],
        ];
        $request = Request::create('/', 'POST', [
            'actividadeconomica' => '4711',
        ]);

        RepresentacionFactura::aplicar($request, $empresa, 'invoice', 1, []);

        $this->assertSame('4711', $request->input('actividadeconomica'));
        $this->assertNull($request->input('tarifaica'));
    }
}
