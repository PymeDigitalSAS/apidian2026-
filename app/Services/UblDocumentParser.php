<?php

namespace App\Services;

/**
 * Parser de documentos electrónicos UBL 2.1 de la DIAN (Invoice, CreditNote,
 * DebitNote). Es el port PHP de parseUblXml() del extractor (unzip.js): produce
 * la misma estructura de datos (cabecera + totales + TaxBreakdown + LineItems).
 *
 * Diferencias respecto al extractor:
 *  - Usa DOMXPath con los namespaces UBL/DIAN registrados (robusto para cac:/cbc:).
 *  - Desenvuelve AttachedDocument: si el XML viene envuelto (factura embebida/
 *    escapada en cbc:Description), extrae el documento interno antes de parsear.
 *
 * Las claves de salida coinciden con las del extractor para que el reporte de
 * Laravel sea consistente con su JSON/Excel.
 */
class UblDocumentParser
{
    /** URIs de namespace estándar (fallback si no se pueden leer del root). */
    private const NS = [
        'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
        'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
        'ext' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2',
        'sts' => 'dian:gov:co:facturaelectronica:Structures-2-1',
    ];

    /** @var \DOMXPath */
    private $xp;

    /** @var \DOMElement */
    private $root;

    /**
     * Parsea el XML y devuelve un arreglo estructurado (o null si no es UBL).
     *
     * @param  string $xml
     * @return array|null
     */
    public function parse(string $xml): ?array
    {
        $xml = $this->unwrapAttachedDocument($xml);

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NOCDATA);
        libxml_clear_errors();
        if (!$loaded || !$dom->documentElement) {
            return null;
        }

        $this->root = $dom->documentElement;
        $rootName = $this->localName($this->root->nodeName);
        if (!in_array($rootName, ['Invoice', 'CreditNote', 'DebitNote'], true)) {
            return null;
        }

        $this->xp = new \DOMXPath($dom);
        foreach (self::NS as $prefix => $fallback) {
            $uri = $this->root->lookupNamespaceURI($prefix) ?: $fallback;
            $this->xp->registerNamespace($prefix, $uri);
        }

        $typeTag = [
            'Invoice'    => 'cbc:InvoiceTypeCode',
            'CreditNote' => 'cbc:CreditNoteTypeCode',
            'DebitNote'  => 'cbc:DebitNoteTypeCode',
        ][$rootName];

        $supplierBase = '//cac:AccountingSupplierParty/cac:Party';
        $customerBase = '//cac:AccountingCustomerParty/cac:Party';

        [$headerTaxesTotal, $breakdown] = $this->extractHeaderTaxes();
        $withholdings = $this->sumWithholdings($breakdown);
        $lines = $this->extractLines($rootName);

        return [
            // Identificación
            'doc_type'          => $rootName,                          // TipoDocumento (Invoice/CreditNote/DebitNote)
            'type_code'         => $this->v('/*/' . $typeTag),         // 01/91/92... (para received_documents)
            'number'            => $this->v('/*/cbc:ID'),
            'prefix'            => $this->v('//sts:InvoiceControl/sts:AuthorizedInvoices/sts:Prefix'),
            'cufe'              => $this->v('//cbc:UUID'),
            'issue_date'        => $this->v('/*/cbc:IssueDate'),
            'issue_time'        => $this->cleanTime($this->v('/*/cbc:IssueTime')),
            'due_date'          => $this->v('/*/cbc:DueDate') ?? $this->v('//cac:PaymentMeans/cbc:PaymentDueDate'),
            'currency'          => $this->v('/*/cbc:DocumentCurrencyCode'),
            'ambient_id'        => $this->v('//cbc:ProfileExecutionID'),
            'note'              => $this->v('/*/cbc:Note'),

            // Partes
            'supplier'          => [
                'nit'  => $this->v($supplierBase . '/cac:PartyTaxScheme/cbc:CompanyID'),
                'name' => $this->firstNonNull([
                    $supplierBase . '/cac:PartyName/cbc:Name',
                    $supplierBase . '/cac:PartyLegalEntity/cbc:RegistrationName',
                    $supplierBase . '/cac:PartyTaxScheme/cbc:RegistrationName',
                ]),
            ],
            'customer'          => [
                'nit'  => $this->v($customerBase . '/cac:PartyTaxScheme/cbc:CompanyID'),
                'name' => $this->firstNonNull([
                    $customerBase . '/cac:PartyName/cbc:Name',
                    $customerBase . '/cac:PartyLegalEntity/cbc:RegistrationName',
                    $customerBase . '/cac:PartyTaxScheme/cbc:RegistrationName',
                ]),
            ],

            // Pago
            'payment_means_id'   => $this->v('//cac:PaymentMeans/cbc:ID'),          // Metodo de Pago (1=contado, 2=crédito)
            'payment_means_code' => $this->v('//cac:PaymentMeans/cbc:PaymentMeansCode'),

            // Totales
            'line_extension'    => $this->n($this->v('//cac:LegalMonetaryTotal/cbc:LineExtensionAmount')),
            'tax_exclusive'     => $this->n($this->v('//cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount')),
            'tax_inclusive'     => $this->n($this->v('//cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount')),
            'allowance_total'   => $this->n($this->v('//cac:LegalMonetaryTotal/cbc:AllowanceTotalAmount')),
            'charge_total'      => $this->n($this->v('//cac:LegalMonetaryTotal/cbc:ChargeTotalAmount')),
            'payable'           => $this->n($this->v('//cac:LegalMonetaryTotal/cbc:PayableAmount')),

            // Impuestos
            'header_taxes_total' => $headerTaxesTotal,
            'withholdings_total' => $withholdings,
            'tax_breakdown'      => $breakdown,   // [{code,name,percent,taxable_amount,amount}]

            // Líneas (claves exactas del extractor)
            'lines'             => $lines,
        ];
    }

    /**
     * Si el XML es un AttachedDocument, devuelve el documento interno
     * (Invoice/CreditNote/DebitNote) embebido en cbc:Description.
     */
    private function unwrapAttachedDocument(string $xml): string
    {
        if (strpos($xml, '<AttachedDocument') === false) {
            return $xml;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NOCDATA);
        libxml_clear_errors();
        if (!$ok) {
            return $xml;
        }

        foreach ($dom->getElementsByTagName('Description') as $desc) {
            $inner = ltrim((string) $desc->nodeValue);
            if (preg_match('/<(Invoice|CreditNote|DebitNote)[\s>]/', $inner)) {
                $pos = strpos($inner, '<?xml');
                if ($pos === false) {
                    $candidates = array_filter([
                        strpos($inner, '<Invoice'),
                        strpos($inner, '<CreditNote'),
                        strpos($inner, '<DebitNote'),
                    ], fn ($p) => $p !== false);
                    $pos = $candidates ? min($candidates) : 0;
                }
                return substr($inner, $pos);
            }
        }

        return $xml;
    }

    /**
     * Impuestos de cabecera: total (suma de cac:TaxTotal/cbc:TaxAmount directos)
     * y desglose por cada TaxSubtotal.
     *
     * @return array{0: float, 1: array}
     */
    private function extractHeaderTaxes(): array
    {
        $total = 0.0;
        $breakdown = [];

        foreach ($this->xp->query('/*/cac:TaxTotal') as $tt) {
            $total += $this->n($this->rel($tt, 'cbc:TaxAmount'));
            foreach ($this->xp->query('cac:TaxSubtotal', $tt) as $sub) {
                $breakdown[] = [
                    'code'           => $this->rel($sub, 'cac:TaxCategory/cac:TaxScheme/cbc:ID'),
                    'name'           => $this->rel($sub, 'cac:TaxCategory/cac:TaxScheme/cbc:Name'),
                    'percent'        => $this->n($this->rel($sub, 'cac:TaxCategory/cbc:Percent')),
                    'taxable_amount' => $this->n($this->rel($sub, 'cbc:TaxableAmount')),
                    'amount'         => $this->n($this->rel($sub, 'cbc:TaxAmount')),
                ];
            }
        }

        return [round($total, 2), $breakdown];
    }

    /**
     * Retenciones (heurística del extractor: nombre con "reten" o código 06/07/08).
     */
    private function sumWithholdings(array $breakdown): float
    {
        $sum = 0.0;
        foreach ($breakdown as $tx) {
            $isReten = (isset($tx['name']) && stripos((string) $tx['name'], 'reten') !== false)
                || in_array((string) ($tx['code'] ?? ''), ['06', '07', '08'], true);
            if ($isReten) {
                $sum += (float) ($tx['amount'] ?? 0);
            }
        }
        return round($sum, 2);
    }

    /**
     * Líneas del documento con las claves exactas del extractor.
     */
    private function extractLines(string $rootName): array
    {
        $lineTag = [
            'Invoice'    => 'cac:InvoiceLine',
            'CreditNote' => 'cac:CreditNoteLine',
            'DebitNote'  => 'cac:DebitNoteLine',
        ][$rootName];

        $out = [];
        foreach ($this->xp->query('/*/' . $lineTag) as $ln) {
            $qtyNode = $this->xp->query('cbc:InvoicedQuantity | cbc:CreditedQuantity | cbc:DebitedQuantity', $ln)->item(0);
            $uom = $qtyNode instanceof \DOMElement ? ($qtyNode->getAttribute('unitCode') ?: null) : null;
            $qty = $this->n($qtyNode ? $qtyNode->nodeValue : null);
            $unitPrice = $this->n($this->rel($ln, 'cac:Price/cbc:PriceAmount'));
            $lineExt = $this->n($this->rel($ln, 'cbc:LineExtensionAmount'));

            [$taxes, $totalTaxLine] = $this->extractLineTaxes($ln);

            $out[] = [
                'Nombre del Producto' => $this->firstRel($ln, ['cac:Item/cbc:Name', 'cac:Item/cbc:Description']),
                'Referencia'          => $this->firstRel($ln, [
                    'cac:Item/cac:SellersItemIdentification/cbc:ID',
                    'cac:Item/cac:StandardItemIdentification/cbc:ID',
                ]),
                'Unidad de Medida'    => $uom,
                'Cantidad'            => $qty,
                'Precio Unitario'     => $unitPrice,
                'Total'               => $lineExt ?: ($qty * $unitPrice),
                'taxes'               => $taxes,
                'total_tax_line'      => $totalTaxLine,
            ];
        }

        return $out;
    }

    /**
     * Impuestos de una línea.
     *
     * @return array{0: array, 1: float}
     */
    private function extractLineTaxes(\DOMNode $ln): array
    {
        $taxes = [];
        $total = 0.0;
        foreach ($this->xp->query('cac:TaxTotal', $ln) as $tt) {
            foreach ($this->xp->query('cac:TaxSubtotal', $tt) as $sub) {
                $amount = $this->n($this->rel($sub, 'cbc:TaxAmount'));
                $taxes[] = [
                    'code'           => $this->rel($sub, 'cac:TaxCategory/cac:TaxScheme/cbc:ID'),
                    'name'           => $this->rel($sub, 'cac:TaxCategory/cac:TaxScheme/cbc:Name'),
                    'percent'        => $this->n($this->rel($sub, 'cac:TaxCategory/cbc:Percent')),
                    'taxable_amount' => $this->n($this->rel($sub, 'cbc:TaxableAmount')),
                    'amount'         => $amount,
                ];
                $total += $amount;
            }
        }
        return [$taxes, round($total, 2)];
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function v(string $query): ?string
    {
        $nodes = $this->xp->query($query);
        if ($nodes && $nodes->length) {
            $val = trim((string) $nodes->item(0)->nodeValue);
            return $val === '' ? null : $val;
        }
        return null;
    }

    private function rel(\DOMNode $ctx, string $query): ?string
    {
        $nodes = $this->xp->query($query, $ctx);
        if ($nodes && $nodes->length) {
            $val = trim((string) $nodes->item(0)->nodeValue);
            return $val === '' ? null : $val;
        }
        return null;
    }

    private function firstNonNull(array $queries): ?string
    {
        foreach ($queries as $q) {
            $val = $this->v($q);
            if ($val !== null) {
                return $val;
            }
        }
        return null;
    }

    private function firstRel(\DOMNode $ctx, array $queries): ?string
    {
        foreach ($queries as $q) {
            $val = $this->rel($ctx, $q);
            if ($val !== null) {
                return $val;
            }
        }
        return null;
    }

    /**
     * Parser numérico robusto (respeta separador decimal), como toNumber() del
     * extractor. Devuelve 0.0 para vacío/no numérico.
     */
    private function n($val): float
    {
        if ($val === null || $val === '') {
            return 0.0;
        }
        $s = trim((string) $val);
        if (is_numeric($s)) {
            return (float) $s;
        }
        // Ambos separadores presentes
        if (strpos($s, '.') !== false && strpos($s, ',') !== false) {
            if (strrpos($s, ',') > strrpos($s, '.')) {
                return (float) str_replace(',', '.', str_replace('.', '', $s));
            }
            return (float) str_replace(',', '', $s);
        }
        if (strpos($s, ',') !== false) {
            return (float) str_replace(',', '.', $s);
        }
        $clean = preg_replace('/[^\d.\-]/', '', $s);
        return is_numeric($clean) ? (float) $clean : 0.0;
    }

    private function cleanTime(?string $t): ?string
    {
        if ($t === null) {
            return null;
        }
        return trim(preg_replace('/[+-]\d{2}:\d{2}$/', '', $t));
    }

    private function localName(string $nodeName): string
    {
        $pos = strpos($nodeName, ':');
        return $pos === false ? $nodeName : substr($nodeName, $pos + 1);
    }
}
