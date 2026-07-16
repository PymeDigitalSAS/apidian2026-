<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Genera el reporte "Por CUFEs" replicando el formato del extractor (unzip.js):
 *
 *  - JSON: un objeto por documento con las mismas claves que parseUblXml()
 *    ('Sub Total', 'Impuestos Cabecera', 'TaxBreakdown', 'LineItems', ...).
 *  - Excel: 2 hojas — "Documentos" (resumen, un documento por fila) y "Lineas"
 *    (una fila por línea × impuesto), con los mismos encabezados de writeOutputs().
 *
 * Además agrega, al final, columnas propias de RADIAN que el extractor no tiene
 * (provienen del listado /api/cufes y del proceso de enriquecimiento):
 * Estado RADIAN, Estado, Fecha Recepción, Valor (texto DIAN) y el estado de
 * extracción por CUFE.
 *
 * Cada fila de entrada trae la metadata del listado en el primer nivel
 * (cufe, tipoDoc, folio, nitEmisor, EstadoRadian, valor, ...) y el detalle del
 * XML bajo `dian` (producido por UblDocumentParser vía RadianEventController).
 */
class CufeReportExporter
{
    // -----------------------------------------------------------------
    // JSON (mismas claves que parseUblXml del extractor + extras RADIAN)
    // -----------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $r = is_array($row) ? $row : (array) $row;
            $d = isset($r['dian']) ? (array) $r['dian'] : [];

            $out[] = [
                'TipoDocumento'             => $d['doc_type'] ?? ($r['tipoDoc'] ?? null),
                'TipoBusqueda'              => $r['tipoBusqueda'] ?? ($r['tipo'] ?? null),
                'Prefijo'                   => $d['prefix'] ?? null,
                'Numero de Factura'         => $d['number'] ?? ($r['folio'] ?? null),
                'CUFE'                      => $d['cufe'] ?? ($r['cufe'] ?? null),
                'Fecha de Creación'         => $d['date_issue'] ?? ($r['fechaEmision'] ?? null),
                'Fecha de Vencimiento'      => $d['due_date'] ?? null,
                'Moneda'                    => $d['currency'] ?? null,
                'NIT del Emisor'            => $d['identification_number'] ?? ($r['nitEmisor'] ?? null),
                'Razon Social del Emisor'   => $d['name_seller'] ?? ($r['nombreEmisor'] ?? null),
                'NIT del Receptor'          => $d['customer'] ?? ($r['nitReceptor'] ?? null),
                'Razon Social del Receptor' => $d['customer_name'] ?? ($r['nombreReceptor'] ?? null),
                'Metodo de Pago'            => $d['payment_means_id'] ?? null,
                'Codigo Medio de Pago'      => $d['payment_means_code'] ?? null,
                'Sub Total'                 => $this->num($d['subtotal'] ?? null),
                'Impuestos Cabecera'        => $this->num($d['total_tax'] ?? null),
                'Retenciones'               => $this->num($d['retention_total'] ?? null),
                'Total a Pagar'             => $this->num($d['total'] ?? null),
                'TaxBreakdown'              => $d['tax_breakdown'] ?? [],
                'LineItems'                 => $this->linesOf($d),

                // Extras RADIAN (no provienen del XML)
                'Estado'                    => $r['estado'] ?? null,
                'Estado RADIAN'             => $r['EstadoRadian'] ?? null,
                'Fecha Recepción'           => $r['fechaRecepcion'] ?? null,
                'Valor (texto DIAN)'        => $r['valor'] ?? null,
                'Estado extracción'         => $r['enrich_status'] ?? null,
                'Detalle extracción'        => $r['enrich_message'] ?? null,
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Excel (2 hojas: Documentos + Lineas, formato del extractor)
    // -----------------------------------------------------------------

    /**
     * Construye el XLSX (2 hojas) y devuelve la ruta del archivo temporal.
     */
    public function toXlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet();

        // --- Hoja Documentos (resumen) ---
        $docsSheet = $spreadsheet->getActiveSheet();
        $docsSheet->setTitle('Documentos');
        $docHeaders = $this->documentHeaders();
        $docData = [];
        foreach ($rows as $row) {
            $docData[] = $this->documentRow(is_array($row) ? $row : (array) $row);
        }
        $this->writeSheet($docsSheet, $docHeaders, $docData);

        // --- Hoja Lineas (una fila por línea × impuesto) ---
        $linesSheet = $spreadsheet->createSheet();
        $linesSheet->setTitle('Lineas');
        $lineHeaders = $this->lineHeaders();
        $lineData = [];
        foreach ($rows as $row) {
            foreach ($this->lineRows(is_array($row) ? $row : (array) $row) as $lineRow) {
                $lineData[] = $lineRow;
            }
        }
        $this->writeSheet($linesSheet, $lineHeaders, $lineData);

        $spreadsheet->setActiveSheetIndex(0);

        $tmpPath = tempnam(sys_get_temp_dir(), 'cufes_') . '.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $tmpPath;
    }

    /** Encabezados de la hoja "Documentos" (extractor + extras RADIAN). */
    private function documentHeaders(): array
    {
        return [
            // ⚠️ Encabezados del extractor (writeOutputs -> resumen)
            'TipoDocumento', 'Numero', 'CUFE', 'Fecha', 'Vencimiento', 'Moneda',
            'NIT_Emisor', 'Nombre_Emisor', 'NIT_Receptor', 'Nombre_Receptor',
            'MetodoPago', 'Subtotal', 'ImpuestosCabecera', 'Retenciones', 'TotalPagar', 'Lineas',
            // Extras RADIAN
            'CodigoMedioPago', 'EstadoRADIAN', 'Estado', 'FechaRecepcion',
            'ValorTextoDIAN', 'EstadoExtraccion', 'DetalleExtraccion',
        ];
    }

    /** Una fila de la hoja "Documentos". */
    private function documentRow(array $r): array
    {
        $d = isset($r['dian']) ? (array) $r['dian'] : [];

        return [
            $d['doc_type'] ?? ($r['tipoDoc'] ?? ''),
            $d['number'] ?? ($r['folio'] ?? ''),
            $d['cufe'] ?? ($r['cufe'] ?? ''),
            $d['date_issue'] ?? ($r['fechaEmision'] ?? ''),
            $d['due_date'] ?? '',
            $d['currency'] ?? '',
            $d['identification_number'] ?? ($r['nitEmisor'] ?? ''),
            $d['name_seller'] ?? ($r['nombreEmisor'] ?? ''),
            $d['customer'] ?? ($r['nitReceptor'] ?? ''),
            $d['customer_name'] ?? ($r['nombreReceptor'] ?? ''),
            $d['payment_means_id'] ?? '',
            $this->num($d['subtotal'] ?? null),
            $this->num($d['total_tax'] ?? null),
            $this->num($d['retention_total'] ?? null),
            $this->num($d['total'] ?? null),
            count($this->linesOf($d)),
            // Extras
            $d['payment_means_code'] ?? '',
            $r['EstadoRadian'] ?? '',
            $r['estado'] ?? '',
            $r['fechaRecepcion'] ?? '',
            $r['valor'] ?? '',
            $r['enrich_status'] ?? '',
            $r['enrich_message'] ?? '',
        ];
    }

    /** Encabezados de la hoja "Lineas" (extractor -> filasLineas). */
    private function lineHeaders(): array
    {
        return [
            'TipoDocumento', 'Numero', 'CUFE', 'Fecha', 'NIT_Emisor', 'NIT_Receptor',
            'Linea', 'Nombre_Producto', 'Referencia', 'Medida', 'Cantidad',
            'PrecioUnitario', 'TotalLinea', 'ImpCode', 'ImpName', 'ImpPercent', 'ImpBase', 'ImpValor',
        ];
    }

    /**
     * Filas de la hoja "Lineas" para un documento: una por cada línea × impuesto.
     */
    private function lineRows(array $r): array
    {
        $d = isset($r['dian']) ? (array) $r['dian'] : [];
        $lines = $this->linesOf($d);
        if (!$lines) {
            return [];
        }

        $tipo   = $d['doc_type'] ?? ($r['tipoDoc'] ?? '');
        $numero = $d['number'] ?? ($r['folio'] ?? '');
        $cufe   = $d['cufe'] ?? ($r['cufe'] ?? '');
        $fecha  = $d['date_issue'] ?? ($r['fechaEmision'] ?? '');
        $nitEmi = $d['identification_number'] ?? ($r['nitEmisor'] ?? '');
        $nitRec = $d['customer'] ?? ($r['nitReceptor'] ?? '');

        $out = [];
        foreach ($lines as $idx => $ln) {
            $ln = (array) $ln;
            $taxes = isset($ln['taxes']) && is_array($ln['taxes']) && $ln['taxes'] ? $ln['taxes'] : [null];
            foreach ($taxes as $tx) {
                $tx = $tx !== null ? (array) $tx : null;
                $out[] = [
                    $tipo,
                    $numero,
                    $cufe,
                    $fecha,
                    $nitEmi,
                    $nitRec,
                    $idx + 1,
                    $ln['Nombre del Producto'] ?? '',
                    $ln['Referencia'] ?? '',
                    $ln['Unidad de Medida'] ?? '',
                    $this->num($ln['Cantidad'] ?? null),
                    $this->num($ln['Precio Unitario'] ?? null),
                    $this->num($ln['Total'] ?? null),
                    $tx ? ($tx['code'] ?? '') : '',
                    $tx ? ($tx['name'] ?? '') : '',
                    $tx ? $this->num($tx['percent'] ?? null) : '',
                    $tx ? $this->num($tx['taxable_amount'] ?? null) : '',
                    $tx ? $this->num($tx['amount'] ?? null) : '',
                ];
            }
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** Líneas del detalle DIAN, normalizadas a array. */
    private function linesOf(array $dian): array
    {
        return isset($dian['lines']) && is_array($dian['lines']) ? $dian['lines'] : [];
    }

    /** Convierte a número si es posible (para que Excel lo trate como monto). */
    private function num($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (float) $value : $value;
    }

    /**
     * Escribe encabezados + filas en una hoja, con estilo y auto-ancho.
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param string[] $headers
     * @param array<int, array<int, mixed>> $data  filas como arreglos ordenados
     */
    private function writeSheet($sheet, array $headers, array $data): void
    {
        $colCount = count($headers);

        foreach ($headers as $index => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1) . '1', $header);
        }

        $lastColLetter = Coordinate::stringFromColumnIndex(max($colCount, 1));
        $headerRange = 'A1:' . $lastColLetter . '1';
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('D9E1F2');
        $sheet->freezePane('A2');

        $rowNum = 2;
        foreach ($data as $rowValues) {
            foreach (array_values($rowValues) as $index => $value) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1) . $rowNum, $value);
            }
            $rowNum++;
        }

        for ($c = 1; $c <= $colCount; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }
    }
}
