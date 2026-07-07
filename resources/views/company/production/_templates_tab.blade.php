{{--
    Pestaña "Plantilla PDF" por tipo de documento.
    Parámetros: $company, $type ('invoice'|'eqdocs'|'support-document'|'payroll')

    Imágenes de vista previa (opcionales, se muestran si existen):
      - invoice:           public/pdf/template{N}.png   (ruta histórica)
      - eqdocs:            public/pdf/eqdocs/template{N}.png
      - support-document:  public/pdf/support-document/template{N}.png
      - payroll:           public/pdf/payroll/template{N}.png
--}}
@php
    $tplTypes = [
        'invoice' => ['folder' => 'invoice', 'column' => 'graphic_representation_template', 'img' => 'pdf', 'label' => 'Factura Electrónica'],
        'eqdocs' => ['folder' => 'pos', 'column' => 'eqdocs_template', 'img' => 'pdf/eqdocs', 'label' => 'Documento Equivalente (POS)'],
        'support-document' => ['folder' => 'support', 'column' => 'support_document_template', 'img' => 'pdf/support-document', 'label' => 'Documento Soporte'],
        'payroll' => ['folder' => 'payroll', 'column' => 'payroll_template', 'img' => 'pdf/payroll', 'label' => 'Nómina Electrónica'],
    ];
    $tplCfg = $tplTypes[$type] ?? $tplTypes['invoice'];

    // Solo plantillas completas: estos 4 tipos usan header{N}/footer{N} ademas
    // del cuerpo. Esto excluye p. ej. invoice 7 y 9 (tirillas por request con
    // is_tirilla2/is_tirilla58, sin header propio).
    $tplIds = [];
    foreach (\Illuminate\Support\Facades\File::files(resource_path("views/pdfs/{$tplCfg['folder']}")) as $tplFile) {
        if (preg_match('/template(\d+)\.blade\.php$/', $tplFile->getFilename(), $tplM)
            && file_exists(resource_path("views/pdfs/{$tplCfg['folder']}/header{$tplM[1]}.blade.php"))
            && file_exists(resource_path("views/pdfs/{$tplCfg['folder']}/footer{$tplM[1]}.blade.php"))) {
            $tplIds[] = (int) $tplM[1];
        }
    }
    sort($tplIds);

    $tplCurrent = $company->{$tplCfg['column']} ?? $company->graphic_representation_template;
    $tplSaveUrl = route('company.production.template.update', ['company' => $company->identification_number, 'type' => $type]);

    // Config de vista previa con datos por defecto. Usa los endpoints
    // preeliminar-view (generan el PDF de ejemplo sin registrar nada en la BD).
    // 'doc' le indica al JS que payload armar y que campo de respuesta leer.
    $tplPreviewCfg = null;
    if ($type === 'invoice') {
        $tplRes = $company->resolutions->where('type_document_id', 1)->first();
        if ($tplRes && optional($company->user)->api_token) {
            $tplNumber = max((int) $tplRes->from, min((int) ($tplRes->next_consecutive ?: $tplRes->from), (int) $tplRes->to));
            $tplDate = \Carbon\Carbon::now();
            try {
                if ($tplRes->date_from && $tplDate->lt(\Carbon\Carbon::parse($tplRes->date_from))) $tplDate = \Carbon\Carbon::parse($tplRes->date_from);
                if ($tplRes->date_to && $tplDate->gt(\Carbon\Carbon::parse($tplRes->date_to))) $tplDate = \Carbon\Carbon::parse($tplRes->date_to);
            } catch (\Exception $e) {
                // fechas de resolución no parseables: se deja la fecha actual
            }
            $tplPreviewCfg = [
                'doc' => 'invoice',
                'url' => url('/api/ubl2.1/invoice/preeliminar-view'),
                'token' => $company->user->api_token,
                'template_token' => password_hash($company->identification_number, PASSWORD_BCRYPT),
                'resolution_number' => $tplRes->resolution,
                'prefix' => $tplRes->prefix,
                'number' => $tplNumber,
                'date' => $tplDate->format('Y-m-d'),
                'municipality_id' => $company->municipality_id ?? 1,
            ];
        }
    } elseif ($type === 'payroll') {
        // Nómina: resolución tipo 9, endpoint payroll/preeliminar-view.
        $tplRes = $company->resolutions->where('type_document_id', 9)->first();
        if ($tplRes && optional($company->user)->api_token) {
            $tplNow = \Carbon\Carbon::now();
            $tplPreviewCfg = [
                'doc' => 'payroll',
                'url' => url('/api/ubl2.1/payroll/preeliminar-view'),
                'token' => $company->user->api_token,
                'template_token' => password_hash($company->identification_number, PASSWORD_BCRYPT),
                'resolution_number' => $tplRes->resolution,
                'prefix' => $tplRes->prefix,
                'consecutive' => max((int) $tplRes->from, 1),
                'municipality_id' => (int) ($company->municipality_id ?? 1),
                'issue_date' => $tplNow->format('Y-m-d'),
                'period_start' => $tplNow->copy()->startOfMonth()->format('Y-m-d'),
                'period_end' => $tplNow->copy()->endOfMonth()->format('Y-m-d'),
                'admission_date' => $tplNow->copy()->subYear()->format('Y-m-d'),
            ];
        }
    } elseif ($type === 'support-document') {
        // Documento soporte: resolución tipo 11, endpoint support-document/preeliminar-view.
        $tplRes = $company->resolutions->where('type_document_id', 11)->first();
        if ($tplRes && optional($company->user)->api_token) {
            $tplDate = \Carbon\Carbon::now();
            try {
                if ($tplRes->date_from && $tplDate->lt(\Carbon\Carbon::parse($tplRes->date_from))) $tplDate = \Carbon\Carbon::parse($tplRes->date_from);
                if ($tplRes->date_to && $tplDate->gt(\Carbon\Carbon::parse($tplRes->date_to))) $tplDate = \Carbon\Carbon::parse($tplRes->date_to);
            } catch (\Exception $e) {
                // fechas no parseables: se deja la fecha actual
            }
            $tplPreviewCfg = [
                'doc' => 'support',
                'url' => url('/api/ubl2.1/support-document/preeliminar-view'),
                'token' => $company->user->api_token,
                'template_token' => password_hash($company->identification_number, PASSWORD_BCRYPT),
                'resolution_number' => $tplRes->resolution,
                'prefix' => $tplRes->prefix,
                'number' => max((int) $tplRes->from, 1),
                'date' => $tplDate->format('Y-m-d'),
                'municipality_id' => (int) ($company->municipality_id ?? 1),
            ];
        }
    } elseif ($type === 'eqdocs') {
        // Documento equivalente POS: resolución tipo 15, endpoint eqdoc/preeliminar-view.
        // La vista previa es solo para POS; los demás subtipos comparten eqdocs_template.
        $tplRes = $company->resolutions->where('type_document_id', 15)->first();
        if ($tplRes && optional($company->user)->api_token) {
            $tplDate = \Carbon\Carbon::now();
            try {
                if ($tplRes->date_from && $tplDate->lt(\Carbon\Carbon::parse($tplRes->date_from))) $tplDate = \Carbon\Carbon::parse($tplRes->date_from);
                if ($tplRes->date_to && $tplDate->gt(\Carbon\Carbon::parse($tplRes->date_to))) $tplDate = \Carbon\Carbon::parse($tplRes->date_to);
            } catch (\Exception $e) {
                // fechas no parseables: se deja la fecha actual
            }
            $tplPreviewCfg = [
                'doc' => 'pos',
                'url' => url('/api/ubl2.1/eqdoc/preeliminar-view'),
                'token' => $company->user->api_token,
                'template_token' => password_hash($company->identification_number, PASSWORD_BCRYPT),
                'resolution_number' => $tplRes->resolution,
                'prefix' => $tplRes->prefix,
                'number' => max((int) $tplRes->from, 1),
                'date' => $tplDate->format('Y-m-d'),
                'municipality_id' => (int) ($company->municipality_id ?? 1),
            ];
        }
    }
@endphp

<div class="card border mt-2 templates-tab" id="templates-{{ $type }}"
    data-type="{{ $type }}"
    data-save-url="{{ $tplSaveUrl }}"
    data-current="{{ $tplCurrent }}"
    @if($tplPreviewCfg) data-preview="{{ json_encode($tplPreviewCfg) }}" @endif>
    <div class="card-body">
        <h3 class="mb-1">Plantilla de Representación Gráfica</h3>
        <p class="text-muted mb-3">
            Seleccione la plantilla con la que se generará el PDF de <strong>{{ $tplCfg['label'] }}</strong> para esta empresa.
            @if($type === 'invoice')
                <br>Esta plantilla también se usa para las <strong>notas crédito y débito</strong>.
            @endif
        </p>

        <div class="row">
            @foreach($tplIds as $tplId)
                <div class="col-6 col-md-4 col-lg-3 mb-3">
                    <div class="card h-100 template-card {{ $tplId == $tplCurrent ? 'border-primary' : 'border' }}" data-template="{{ $tplId }}" style="cursor:pointer;">
                        <div class="position-relative bg-light d-flex align-items-center justify-content-center" style="height: 220px; overflow:hidden;">
                            @php $tplImg = $tplCfg['img'] . '/template' . $tplId . '.png'; @endphp
                            @if(file_exists(public_path($tplImg)) || file_exists(base_path($tplImg)))
                            <img src="{{ asset($tplImg) }}"
                                alt="Plantilla {{ $tplId }}"
                                class="w-100 h-100"
                                style="object-fit: contain;"
                                loading="lazy">
                            @else
                            <div class="flex-column align-items-center justify-content-center text-muted" style="display:flex; height:100%;">
                                <i class="fas fa-file-pdf fa-3x mb-2"></i>
                                <small>Vista previa no disponible</small>
                            </div>
                            @endif
                            <span class="badge badge-primary bg-primary position-absolute template-current-badge"
                                style="top:8px; right:8px; {{ $tplId == $tplCurrent ? '' : 'display:none;' }}">Actual</span>
                        </div>
                        <div class="card-body p-2 d-flex align-items-center justify-content-between">
                            <div class="form-check mb-0">
                                <input class="form-check-input template-radio" type="radio"
                                    name="template-{{ $type }}" id="template-{{ $type }}-{{ $tplId }}"
                                    value="{{ $tplId }}" {{ $tplId == $tplCurrent ? 'checked' : '' }}>
                                <label class="form-check-label fw-bold" for="template-{{ $type }}-{{ $tplId }}">
                                    Plantilla {{ $tplId }}
                                </label>
                            </div>
                            @if($tplPreviewCfg)
                                <button type="button" class="btn btn-sm btn-outline-secondary template-preview-btn"
                                    data-template="{{ $tplId }}" title="Generar PDF de ejemplo con esta plantilla">
                                    <i class="fas fa-eye"></i> PDF
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="d-flex align-items-center mt-2">
            <button type="button" class="btn btn-primary template-save-btn">
                <i class="fas fa-save me-1"></i> Guardar plantilla
            </button>
            <span class="ml-3 ms-3 template-save-status"></span>
        </div>

        @if(in_array($type, ['invoice', 'payroll', 'support-document', 'eqdocs']) && !$tplPreviewCfg)
            <div class="alert alert-info mt-3 mb-0">
                Para habilitar la vista previa en PDF, la empresa debe tener una resolución de {{ $type === 'payroll' ? 'nómina' : ($type === 'support-document' ? 'documento soporte' : ($type === 'eqdocs' ? 'documento equivalente (POS)' : 'factura')) }} configurada.
            </div>
        @endif
    </div>
</div>

@once
    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const csrf = document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').content : '{{ csrf_token() }}';

            document.querySelectorAll('.templates-tab').forEach(function(tab) {
                // Seleccionar tarjeta (clic en cualquier parte de la tarjeta)
                tab.querySelectorAll('.template-card').forEach(function(card) {
                    card.addEventListener('click', function(e) {
                        if (e.target.closest('.template-preview-btn')) return;
                        const radio = card.querySelector('.template-radio');
                        radio.checked = true;
                        tab.querySelectorAll('.template-card').forEach(function(c) {
                            c.classList.toggle('border-primary', c === card);
                        });
                    });
                });

                // Guardar plantilla seleccionada
                const saveBtn = tab.querySelector('.template-save-btn');
                const status = tab.querySelector('.template-save-status');
                saveBtn.addEventListener('click', function() {
                    const selected = tab.querySelector('.template-radio:checked');
                    if (!selected) return;
                    saveBtn.disabled = true;
                    status.innerHTML = '<span class="text-info"><i class="fa fa-spinner fa-spin"></i> Guardando...</span>';
                    fetch(tab.dataset.saveUrl, {
                        method: 'PUT',
                        headers: {
                            'X-CSRF-TOKEN': csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({ template: parseInt(selected.value, 10) })
                    })
                    .then(function(res) { return res.json().then(function(data) { return { ok: res.ok, data: data }; }); })
                    .then(function(r) {
                        if (r.ok && r.data.success) {
                            tab.dataset.current = r.data.template;
                            tab.querySelectorAll('.template-card').forEach(function(c) {
                                const isCurrent = parseInt(c.dataset.template, 10) === r.data.template;
                                c.querySelector('.template-current-badge').style.display = isCurrent ? '' : 'none';
                            });
                            status.innerHTML = '<span class="text-success"><i class="fa fa-check-circle"></i> ' + r.data.message + '</span>';
                        } else {
                            status.innerHTML = '<span class="text-danger"><i class="fa fa-times-circle"></i> ' + (r.data.error || 'No se pudo guardar la plantilla.') + '</span>';
                        }
                    })
                    .catch(function() {
                        status.innerHTML = '<span class="text-danger"><i class="fa fa-times-circle"></i> Error inesperado al guardar.</span>';
                    })
                    .finally(function() { saveBtn.disabled = false; });
                });

                // Vista previa en PDF con datos por defecto (solo tipos con data-preview)
                if (!tab.dataset.preview) return;
                const cfg = JSON.parse(tab.dataset.preview);

                const LEYENDA_HEAD = 'EJEMPLO DE LEYENDA EN EL ENCABEZADO (head_note)';
                const LEYENDA_FOOT = 'Ejemplo de leyenda al pie del documento (foot_note).';

                function buildInvoicePayload(templateId) {
                    return {
                        number: cfg.number,
                        type_document_id: 1,
                        resolution_number: cfg.resolution_number,
                        prefix: cfg.prefix,
                        date: cfg.date,
                        time: '10:30:00',
                        invoice_template: String(templateId),
                        template_token: cfg.template_token,
                        hide_watermark: true,
                        head_note: LEYENDA_HEAD,
                        foot_note: LEYENDA_FOOT,
                        notes: 'Documento de ejemplo generado para previsualizar la plantilla. No tiene validez fiscal.',
                        customer: {
                            identification_number: '222222222222',
                            name: 'CONSUMIDOR FINAL DE EJEMPLO',
                            phone: '3001234567',
                            address: 'Calle 1 # 2 - 34',
                            email: 'cliente@ejemplo.com',
                            merchant_registration: '0000000-00',
                            type_document_identification_id: 3,
                            type_organization_id: 2,
                            type_regime_id: 2,
                            municipality_id: cfg.municipality_id
                        },
                        payment_form: [{
                            payment_form_id: 1,
                            payment_method_id: 10,
                            payment_due_date: cfg.date,
                            duration_measure: '0'
                        }],
                        legal_monetary_totals: {
                            line_extension_amount: '100000.00',
                            tax_exclusive_amount: '100000.00',
                            tax_inclusive_amount: '119000.00',
                            allowance_total_amount: '0.00',
                            charge_total_amount: '0.00',
                            payable_amount: '119000.00'
                        },
                        tax_totals: [{
                            tax_id: 1,
                            tax_amount: '19000.00',
                            percent: '19.00',
                            taxable_amount: '100000.00'
                        }],
                        invoice_lines: [{
                            unit_measure_id: 70,
                            invoiced_quantity: '1',
                            line_extension_amount: '100000.00',
                            free_of_charge_indicator: false,
                            tax_totals: [{
                                tax_id: 1,
                                tax_amount: '19000.00',
                                taxable_amount: '100000.00',
                                percent: '19.00'
                            }],
                            description: 'Producto de ejemplo para vista previa',
                            notes: 'Nota de ejemplo en la línea del producto',
                            code: 'DEMO-001',
                            type_item_identification_id: 4,
                            price_amount: '100000.00',
                            base_quantity: '1'
                        }]
                    };
                }

                function buildPayrollPayload(templateId) {
                    return {
                        type_document_id: 9,
                        resolution_number: cfg.resolution_number,
                        prefix: cfg.prefix,
                        consecutive: cfg.consecutive,
                        payroll_period_id: 1,
                        payroll_template: String(templateId),
                        template_token: cfg.template_token,
                        head_note: LEYENDA_HEAD,
                        foot_note: LEYENDA_FOOT,
                        notes: 'Nómina de ejemplo generada para previsualizar la plantilla. No tiene validez fiscal.',
                        period: {
                            admision_date: cfg.admission_date,
                            settlement_start_date: cfg.period_start,
                            settlement_end_date: cfg.period_end,
                            worked_time: 30,
                            issue_date: cfg.issue_date
                        },
                        worker: {
                            type_worker_id: 1,
                            sub_type_worker_id: 1,
                            payroll_type_document_identification_id: 1,
                            municipality_id: cfg.municipality_id,
                            type_contract_id: 1,
                            high_risk_pension: false,
                            identification_number: '1234567890',
                            surname: 'PÉREZ',
                            first_name: 'JUAN',
                            address: 'Calle 1 # 2 - 34',
                            integral_salarary: false,
                            salary: 1300000
                        },
                        payment: { payment_method_id: 1 },
                        payment_dates: [{ payment_date: cfg.period_end }],
                        accrued: {
                            worked_days: 30,
                            salary: 1300000,
                            transportation_allowance: 200000,
                            accrued_total: 1500000
                        },
                        deductions: {
                            eps_type_law_deductions_id: 1,
                            eps_deduction: 52000,
                            pension_type_law_deductions_id: 1,
                            pension_deduction: 52000,
                            deductions_total: 104000
                        }
                    };
                }

                function buildSupportPayload(templateId) {
                    return {
                        type_document_id: 11,
                        resolution_number: cfg.resolution_number,
                        prefix: cfg.prefix,
                        number: cfg.number,
                        date: cfg.date,
                        time: '10:30:00',
                        invoice_template: String(templateId),
                        template_token: cfg.template_token,
                        type_operation_id: 23,
                        head_note: LEYENDA_HEAD,
                        foot_note: LEYENDA_FOOT,
                        notes: 'Documento soporte de ejemplo generado para previsualizar la plantilla. No tiene validez fiscal.',
                        seller: {
                            identification_number: '900123456',
                            type_document_identification_id: 6,
                            type_organization_id: 1,
                            name: 'PROVEEDOR DE EJEMPLO SAS',
                            phone: '3001234567',
                            address: 'Calle 10 # 20 - 30',
                            email: 'proveedor@ejemplo.com',
                            merchant_registration: '0000000-00',
                            postal_zone_code: 110111,
                            municipality_id: cfg.municipality_id,
                            type_regime_id: 2
                        },
                        payment_form: {
                            payment_form_id: 1,
                            payment_method_id: 10,
                            payment_due_date: cfg.date,
                            duration_measure: '0'
                        },
                        legal_monetary_totals: {
                            line_extension_amount: '100000.00',
                            tax_exclusive_amount: '100000.00',
                            tax_inclusive_amount: '119000.00',
                            allowance_total_amount: '0.00',
                            charge_total_amount: '0.00',
                            payable_amount: '119000.00'
                        },
                        tax_totals: [{
                            tax_id: 1,
                            tax_amount: '19000.00',
                            percent: '19.00',
                            taxable_amount: '100000.00'
                        }],
                        invoice_lines: [{
                            unit_measure_id: 70,
                            invoiced_quantity: '1',
                            line_extension_amount: '100000.00',
                            free_of_charge_indicator: false,
                            tax_totals: [{
                                tax_id: 1,
                                tax_amount: '19000.00',
                                taxable_amount: '100000.00',
                                percent: '19.00'
                            }],
                            description: 'Servicio de ejemplo para vista previa',
                            notes: 'Nota de ejemplo en la línea',
                            code: 'DEMO-001',
                            type_item_identification_id: 4,
                            price_amount: '100000.00',
                            base_quantity: '1',
                            type_generation_transmition_id: 1,
                            start_date: cfg.date
                        }]
                    };
                }

                function buildPosPayload(templateId) {
                    return {
                        type_document_id: 15,
                        resolution_number: cfg.resolution_number,
                        prefix: cfg.prefix,
                        number: cfg.number,
                        date: cfg.date,
                        time: '10:30:00',
                        invoice_template: String(templateId),
                        template_token: cfg.template_token,
                        hide_watermark: true,
                        head_note: LEYENDA_HEAD,
                        foot_note: LEYENDA_FOOT,
                        notes: 'Documento equivalente POS de ejemplo generado para previsualizar la plantilla. No tiene validez fiscal.',
                        software_manufacturer: {
                            name: 'FABRICANTE DE EJEMPLO',
                            business_name: 'FABRICANTE DE EJEMPLO SAS',
                            software_name: 'API DIAN'
                        },
                        buyer_benefits: { code: '1', name: 'Puntos', points: '100' },
                        cash_information: {
                            plate_number: 'CAJA-01',
                            location: 'Sede Principal',
                            cashier: 'Cajero de Ejemplo',
                            cash_type: 'POS',
                            sales_code: 'V-001',
                            subtotal: '100000.00'
                        },
                        postal_zone_code: 110111,
                        customer: {
                            identification_number: '222222222222',
                            name: 'CONSUMIDOR FINAL DE EJEMPLO',
                            phone: '3001234567',
                            address: 'Calle 1 # 2 - 34',
                            email: 'cliente@ejemplo.com',
                            merchant_registration: '0000000-00',
                            type_document_identification_id: 3,
                            type_organization_id: 2,
                            type_regime_id: 2,
                            municipality_id: cfg.municipality_id
                        },
                        payment_form: {
                            payment_form_id: 1,
                            payment_method_id: 10,
                            payment_due_date: cfg.date,
                            duration_measure: '0'
                        },
                        legal_monetary_totals: {
                            line_extension_amount: '100000.00',
                            tax_exclusive_amount: '100000.00',
                            tax_inclusive_amount: '119000.00',
                            allowance_total_amount: '0.00',
                            charge_total_amount: '0.00',
                            payable_amount: '119000.00'
                        },
                        tax_totals: [{
                            tax_id: 1,
                            tax_amount: '19000.00',
                            percent: '19.00',
                            taxable_amount: '100000.00'
                        }],
                        invoice_lines: [{
                            unit_measure_id: 70,
                            invoiced_quantity: '1',
                            line_extension_amount: '100000.00',
                            free_of_charge_indicator: false,
                            tax_totals: [{
                                tax_id: 1,
                                tax_amount: '19000.00',
                                taxable_amount: '100000.00',
                                percent: '19.00'
                            }],
                            description: 'Producto de ejemplo POS',
                            notes: 'Nota de ejemplo en la línea',
                            code: 'DEMO-001',
                            type_item_identification_id: 4,
                            price_amount: '100000.00',
                            base_quantity: '1'
                        }]
                    };
                }

                function buildPreviewPayload(templateId) {
                    if (cfg.doc === 'payroll') return buildPayrollPayload(templateId);
                    if (cfg.doc === 'support') return buildSupportPayload(templateId);
                    if (cfg.doc === 'pos') return buildPosPayload(templateId);
                    return buildInvoicePayload(templateId);
                }

                tab.querySelectorAll('.template-preview-btn').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        const original = btn.innerHTML;
                        btn.disabled = true;
                        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';
                        fetch(cfg.url, {
                            method: 'POST',
                            headers: {
                                'Authorization': 'Bearer ' + cfg.token,
                                'Content-Type': 'application/json',
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify(buildPreviewPayload(btn.dataset.template))
                        })
                        .then(function(res) { return res.json().then(function(data) { return { ok: res.ok, data: data }; }); })
                        .then(function(r) {
                            const b64 = r.data.base64invoicepdf || r.data.base64payrollpdf || r.data.base64supportpdf || r.data.base64eqdocpdf;
                            if (r.ok && b64) {
                                const bytes = atob(b64);
                                const buffer = new Uint8Array(bytes.length);
                                for (let i = 0; i < bytes.length; i++) buffer[i] = bytes.charCodeAt(i);
                                const blob = new Blob([buffer], { type: 'application/pdf' });
                                window.open(URL.createObjectURL(blob), '_blank');
                            } else {
                                let msg = r.data.message || 'No se pudo generar la vista previa.';
                                if (r.data.errors) {
                                    msg += '\n' + Object.values(r.data.errors).map(function(v) { return v.join(' '); }).join('\n');
                                }
                                alert(msg);
                            }
                        })
                        .catch(function() { alert('Error inesperado generando la vista previa.'); })
                        .finally(function() {
                            btn.disabled = false;
                            btn.innerHTML = original;
                        });
                    });
                });
            });
        });
    </script>
    @endpush
@endonce
