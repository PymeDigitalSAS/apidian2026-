@extends('layouts.app')
@php
    $defaultFechaDesde = date('Y-m-01');
    $defaultFechaHasta = date('Y-m-d');
@endphp
@section('content')
@php
    $extractorConfigured = !empty($config['url']);
@endphp

<header class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h2>Extractor de factura        <p class="text-muted mb-0" style="font-size:.9rem;">Consulta documentos en la DIAN y descárgalos en Excel o JSON.</p>
    </div>
    <div class="right-wrapper text-end mt-auto pb-1">
        <button type="button" id="btn-test-connection"
                class="btn btn-outline-primary"
                @if(!$extractorConfigured) disabled title="EXTRACTOR_URL no está configurada en .env" @endif>
            <i class="fas fa-plug mr-1"></i> Probar conexión
        </button>
    </div>
</header>

@if(!$extractorConfigured)
    <div class="alert alert-warning mb-3" role="alert">
        <i class="fas fa-exclamation-triangle mr-1"></i>
        <strong>Extractor no configurado.</strong> Contacta al administrador para habilitar esta herramienta
        (variable <code>EXTRACTOR_URL</code> en <code>.env</code> y luego <code>php artisan config:clear</code>).
    </div>
@endif

<div id="connection-result" class="mt-2 mb-3" style="display:none;"></div>

<div class="card mb-3">
    <div class="card-body">
        <div class="tab-content">
            <div class="tab-pane fade show active" id="pane-url" role="tabpanel">
                <form id="extract-form">
                    @csrf
                    <div class="row">
                        <div class="form-group col-md-12">
                            <label for="extr-url">Enlace de consulta DIAN (Token)</label>
                            <input type="url" id="extr-url" name="url" class="form-control"
                                   placeholder="https://catalogo-vpfe.dian.gov.co/User/AuthToken?..."
                                   pattern="^https://catalogo-vpfe(-hab)?\.dian\.gov\.co/User/AuthToken.*"
                                   title="Debe ser un enlace User/AuthToken de DIAN (catalogo-vpfe o catalogo-vpfe-hab)"
                                   required>
                            <small class="form-text text-muted">
                                Pega el enlace <code>User/AuthToken</code> emitido por DIAN.
                            </small>
                        </div>

                        <div class="form-group col-md-4">
                            <label for="extr-tipo">Tipo de documentos</label>
                            <select id="extr-tipo" name="tipo" class="form-control" required>
                                <option value="Sent">Enviados</option>
                                <option value="Received">Recibidos</option>
                            </select>
                        </div>

                        <div class="form-group col-md-4">
                            <label for="extr-fecha-desde">Desde</label>
                            <input type="date" id="extr-fecha-desde" name="fechaDesde" class="form-control" value="{{ $defaultFechaDesde }}" required>
                        </div>

                        <div class="form-group col-md-4">
                            <label for="extr-fecha-hasta">Hasta</label>
                            <input type="date" id="extr-fecha-hasta" name="fechaHasta" class="form-control" value="{{ $defaultFechaHasta }}" required>
                        </div>
                    </div>

                    <button type="submit" id="btn-extract" class="btn btn-primary"
                            @if(!$extractorConfigured) disabled title="EXTRACTOR_URL no está configurada en .env" @endif>
                        <i class="fas fa-play mr-1"></i> Iniciar extracción
                    </button>
                </form>
            </div>

            {{-- Modo "Por Certificado" oculto: aún no disponible. --}}
            <div class="tab-pane fade" id="pane-cert" role="tabpanel" style="display:none;">
                <form id="extract-cert-form" enctype="multipart/form-data">
                    @csrf
                    <div class="row">
                        <div class="form-group col-md-12">
                            <label for="cert-extr-url">Ambiente DIAN</label>
                            <select id="cert-extr-url" name="url" class="form-control" required>
                                <option value="https://certificate-vpfe-hab.dian.gov.co/">Habilitación</option>
                                <option value="https://certificate-vpfe.dian.gov.co">Producción</option>
                            </select>
                        </div>

                        <div class="form-group col-md-4">
                            <label for="cert-extr-tipo">Tipo de documentos</label>
                            <select id="cert-extr-tipo" name="tipo" class="form-control" required>
                                <option value="Sent">Enviados</option>
                                <option value="Received">Recibidos</option>
                            </select>
                        </div>

                        <div class="form-group col-md-4">
                            <label for="cert-extr-fecha-desde">Desde</label>
                            <input type="date" id="cert-extr-fecha-desde" name="fechaDesde" class="form-control" value="{{ $defaultFechaDesde }}" required>
                        </div>

                        <div class="form-group col-md-4">
                            <label for="cert-extr-fecha-hasta">Hasta</label>
                            <input type="date" id="cert-extr-fecha-hasta" name="fechaHasta" class="form-control" value="{{ $defaultFechaHasta }}" required>
                        </div>

                        <div class="form-group col-md-6">
                            <label for="cert-extr-file">Certificado (.p12 / .pfx) <small class="text-muted">(opcional)</small></label>
                            <div class="custom-file">
                                <input type="file" class="custom-file-input" id="cert-extr-file" name="certificate" accept=".p12,.pfx">
                                <label class="custom-file-label" for="cert-extr-file">Seleccionar archivo...</label>
                            </div>
                        </div>

                        <div class="form-group col-md-6">
                            <label for="cert-extr-password">Contraseña del certificado <small class="text-muted">(opcional)</small></label>
                            <input type="password" id="cert-extr-password" name="p12Password" class="form-control" autocomplete="new-password">
                        </div>

                        <div class="form-group col-md-6">
                            <label for="cert-extr-usercode">User code <small class="text-muted">(opcional)</small></label>
                            <input type="text" id="cert-extr-usercode" name="userCode" class="form-control" placeholder="NIT u otro identificador">
                        </div>
                    </div>

                    <button type="submit" id="btn-extract-cert" class="btn btn-warning">
                        <i class="fas fa-shield-alt mr-1"></i> Iniciar extracción
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<div id="extractor-output" class="mt-4" style="display:none;">
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Progreso</strong>
            <span id="job-status-badge" class="badge badge-secondary">Iniciando…</span>
        </div>
        <div class="card-body p-0">
            <div id="job-log" style="background:#1e1e1e;color:#dcdcdc;font-family:Consolas,Menlo,monospace;font-size:.82rem;padding:12px;max-height:300px;overflow-y:auto;white-space:pre-wrap;"></div>
        </div>
        <div class="card-footer text-muted" style="font-size:.8rem;">
            Job ID: <code id="job-id-display">—</code>
        </div>
    </div>

    <div id="job-summary" class="mb-3" style="display:none;"></div>

    <div id="job-downloads" class="mb-3" style="display:none;">
        <a id="btn-download-excel" href="#" class="btn btn-success mr-2" style="display:none;" target="_blank">
            <i class="fas fa-file-excel mr-1"></i> Descargar Excel
        </a>
        <a id="btn-download-json" href="#" class="btn btn-outline-secondary" style="display:none;" target="_blank">
            <i class="fas fa-file-code mr-1"></i> Descargar JSON
        </a>
    </div>

    <div id="job-data-section" class="card mb-3" style="display:none;">
        <div class="card-header">
            <strong>Documentos extraídos</strong>
/div>
    </div>

    <div id="job-data-section" class="card mb-3" style="display:none;">
        <div class="card-header">
            <strong><i class="fas fa-file-invoice mr-1"></i> Documentos extraídos</strong>
div>

    <div id="job-data-section" class="card mb-3" style="display:none;">
        <div class="card-header">
            <strong><i class="fas fa-file-invoice mr-1"></i> Documentos extraídos</strong>
div>

    <div id="job-data-section" class="card mb-3" style="display:none;">
        <div class="card-header">
            <strong><i class="fas fa-file-invoice mr-1"></i> Documentos extraídos</strong>
/div>

    <div id="job-data-section" class="card mb-3" style="display:none;">
        <div class="card-header">
            <strong><i class="fas fa-file-invoice mr-1"></i> Documentos extraídos</strong>
            <span id="job-data-count" class="badge badge-info ml-2">0</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>#</th>
                            <th>Tipo</th>
                            <th>Número</th>
                            <th>Fecha</th>
                            <th>NIT Emisor</th>
                            <th>Razón social Emisor</th>
                            <th class="text-right">Total</th>
                            <th>CUFE</th>
                        </tr>
                    </thead>
                    <tbody id="job-data-tbody"></tbody>
                </table>
            </div>
        </div>
        <div class="card-footer">
            <details>
                <summary><strong>Ver JSON completo</strong></summary>
                <pre id="job-data-json" style="max-height:400px;overflow:auto;background:#f8f9fa;padding:8px;font-size:.78rem;margin-top:8px;"></pre>
            </details>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
$(function () {
    const testBtn = $('#btn-test-connection');
    const testResult = $('#connection-result');

    testBtn.on('click', function () {
        const originalHtml = testBtn.html();
        testBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Probando...');
        testResult.hide().empty();

        $.ajax({
            url: '{{ route('extractor.test') }}',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            dataType: 'json'
        }).done(function (data) {
            testResult.html(
                '<div class="alert alert-success mb-0">' +
                '<i class="fas fa-check-circle mr-1"></i>' +
                '<strong>Conexión exitosa</strong> &mdash; ' + (data.message || '') +
                '</div>'
            ).show();
        }).fail(function (xhr) {
            const resp = xhr.responseJSON || {};
            testResult.html(
                '<div class="alert alert-danger mb-0">' +
                '<i class="fas fa-times-circle mr-1"></i>' +
                '<strong>Falló la conexión</strong> &mdash; ' +
                (resp.message || 'Error HTTP ' + xhr.status) +
                '</div>'
            ).show();
        }).always(function () {
            testBtn.prop('disabled', false).html(originalHtml);
        });
    });

    // ====== Estado compartido del job en vivo ======
    const outputBox = $('#extractor-output');
    const logBox = $('#job-log');
    const statusBadge = $('#job-status-badge');
    const jobIdDisplay = $('#job-id-display');
    const summaryBox = $('#job-summary');
    const dataSection = $('#job-data-section');
    const dataCount = $('#job-data-count');
    const dataTbody = $('#job-data-tbody');
    const dataJson = $('#job-data-json');
    const downloadsSection = $('#job-downloads');
    const btnDownloadExcel = $('#btn-download-excel');
    const btnDownloadJson = $('#btn-download-json');
    const downloadUrlTpl = '{{ url('extractor/download') }}/';

    const jobUrlTpl = '{{ url('extractor/job') }}/';
    let pollHandle = null;
    let renderedLogLines = 0;
    let activeButton = null;
    let activeButtonHtml = null;
    let extractionInProgress = false;

    function beforeUnloadHandler(e) {
        if (!extractionInProgress) return;
        e.preventDefault();
        e.returnValue = 'Hay una extracción en curso. Si recargas perderás la visibilidad del progreso (la extracción seguirá corriendo en el servidor, pero no podrás ver el resultado).';
        return e.returnValue;
    }
    window.addEventListener('beforeunload', beforeUnloadHandler);

    function setBadge(status) {
        const map = {
            running:  ['badge-primary', '<i class="fas fa-spinner fa-spin mr-1"></i> En ejecución'],
            done:     ['badge-success', '<i class="fas fa-check mr-1"></i> Completado'],
            partial:  ['badge-warning', '<i class="fas fa-exclamation-triangle mr-1"></i> Parcial'],
            error:    ['badge-danger',  '<i class="fas fa-times mr-1"></i> Error'],
            starting: ['badge-secondary', '<i class="fas fa-hourglass-half mr-1"></i> Iniciando…'],
        };
        const [cls, html] = map[status] || map.starting;
        statusBadge.removeClass(function (i, c) {
            return (c.match(/(^|\s)badge-\S+/g) || []).join(' ');
        }).addClass('badge ' + cls).html(html);
    }

    function appendLogLines(lines) {
        if (!Array.isArray(lines)) return;
        const fresh = lines.slice(renderedLogLines);
        if (!fresh.length) return;
        const html = fresh.map(line => $('<div>').text(line).html()).join('\n') + '\n';
        logBox.append(html);
        renderedLogLines = lines.length;
        logBox.scrollTop(logBox[0].scrollHeight);
    }

    function fmtMoney(n) {
        if (n === null || n === undefined || n === '') return '';
        const num = Number(n);
        if (isNaN(num)) return String(n);
        return num.toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function renderData(docs) {
        dataCount.text(docs.length);
        dataTbody.empty();
        docs.forEach((d, i) => {
            const cufe = d['CUFE'] || '';
            const cufeShort = cufe ? cufe.slice(0, 12) + '…' : '';
            const row = $('<tr>');
            row.append($('<td>').text(i + 1));
            row.append($('<td>').text(d['TipoDocumento'] || ''));
            row.append($('<td>').text(d['Numero de Factura'] || d['Prefijo'] || ''));
            row.append($('<td>').text(d['Fecha de Creación'] || ''));
            row.append($('<td>').text(d['NIT del Emisor'] || ''));
            row.append($('<td>').text(d['Razon Social del Emisor'] || ''));
            row.append($('<td>').addClass('text-right').text(fmtMoney(d['Total a Pagar'])));
            row.append($('<td>').attr('title', cufe).append($('<code>').text(cufeShort)));
            dataTbody.append(row);
        });
        dataJson.text(JSON.stringify(docs, null, 2));
        dataSection.show();
    }

    function showSummary(level, message) {
        const map = {
            success: ['alert-success', 'fa-check-circle'],
            info:    ['alert-info',    'fa-info-circle'],
            warning: ['alert-warning', 'fa-exclamation-triangle'],
            danger:  ['alert-danger',  'fa-times-circle'],
        };
        const [cls, icon] = map[level] || map.info;
        summaryBox.html('<div class="alert ' + cls + ' mb-0"><i class="fas ' + icon + ' mr-1"></i>' + message + '</div>').show();
    }

    function stopPolling() {
        if (pollHandle) { clearTimeout(pollHandle); pollHandle = null; }
        if (activeButton) {
            activeButton.prop('disabled', false).html(activeButtonHtml);
            activeButton = null;
        }
        extractionInProgress = false;
    }

    function pollJob(jobId) {
        $.ajax({
            url: jobUrlTpl + encodeURIComponent(jobId),
            method: 'GET',
            dataType: 'json',
            cache: false
        }).done(function (resp) {
            if (!resp || resp.success === false) {
                showSummary('danger', 'No se pudo consultar el estado del job: ' + (resp && resp.message ? resp.message : 'desconocido'));
                setBadge('error');
                stopPolling();
                return;
            }
            appendLogLines(resp.log);

            if (resp.status === 'running') {
                setBadge('running');
                pollHandle = setTimeout(() => pollJob(jobId), 1500);
                return;
            }

            // done o error
            const docs = Array.isArray(resp.data) ? resp.data : [];
            if (resp.status === 'done') {
                if (resp.partial) {
                    setBadge('partial');
                    showSummary('warning', 'Extracción terminada con resultado parcial. Documentos: <strong>' + docs.length + '</strong>.');
                } else if (docs.length === 0) {
                    setBadge('done');
                    showSummary('info', 'La extracción finalizó pero no se encontraron documentos en el rango de fechas.');
                } else {
                    setBadge('done');
                    showSummary('success', 'Extracción completada. Documentos: <strong>' + docs.length + '</strong>.');
                }
                if (docs.length) {
                    renderData(docs);
                    setupDownloadsFromLog(resp.log, resp.folder);
                }
            } else {
                setBadge('error');
                showSummary('danger', 'Error en la extracción: ' + (resp.error || 'desconocido'));
            }
            stopPolling();
        }).fail(function (xhr) {
            const resp = xhr.responseJSON || {};
            showSummary('danger', 'Fallo al consultar el job: ' + (resp.message || 'HTTP ' + xhr.status));
            setBadge('error');
            stopPolling();
        });
    }

    function resetOutput(button) {
        activeButton = button;
        activeButtonHtml = button.html();
        button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Extrayendo…');

        outputBox.show();
        logBox.empty();
        renderedLogLines = 0;
        summaryBox.hide().empty();
        dataSection.hide();
        dataTbody.empty();
        dataJson.text('');
        dataCount.text('0');
        jobIdDisplay.text('—');
        downloadsSection.hide();
        btnDownloadExcel.hide().attr('href', '#');
        btnDownloadJson.hide().attr('href', '#');
        setBadge('starting');
        extractionInProgress = true;
    }

    function setupDownloadsFromLog(logLines, folder) {
        if (!Array.isArray(logLines)) return;
        let excelRel = null;
        let jsonRel = null;
        for (const line of logLines) {
            const mE = line.match(/^EXCEL_PATH:\s*(.+)$/);
            if (mE) excelRel = mE[1].trim();
            const mJ = line.match(/^JSON_PATH:\s*(.+)$/);
            if (mJ) jsonRel = mJ[1].trim();
        }
        // Si JSON_PATH viene sin la carpeta, anteponer la carpeta del job
        if (jsonRel && folder && jsonRel.indexOf('/') === -1 && jsonRel.indexOf('\\') === -1) {
            jsonRel = folder + '/' + jsonRel;
        }
        let shown = false;
        if (excelRel) {
            btnDownloadExcel.attr('href', downloadUrlTpl + excelRel.split('/').map(encodeURIComponent).join('/')).show();
            shown = true;
        }
        if (jsonRel) {
            btnDownloadJson.attr('href', downloadUrlTpl + jsonRel.split('/').map(encodeURIComponent).join('/')).show();
            shown = true;
        }
        if (shown) downloadsSection.show();
    }

    function handleStartResponse(resp) {
        if (!resp || !resp.jobId) {
            setBadge('error');
            showSummary('danger', 'El extractor no devolvió un jobId. Respuesta: ' + JSON.stringify(resp));
            stopPolling();
            return;
        }
        jobIdDisplay.text(resp.jobId);
        setBadge('running');
        pollJob(resp.jobId);
    }

    function handleStartFail(xhr) {
        const resp = xhr.responseJSON || {};
        const errors = resp.errors ? Object.values(resp.errors).flat().join(' · ') : '';
        showSummary('danger', (resp.message || 'HTTP ' + xhr.status) + (errors ? ' — ' + errors : ''));
        setBadge('error');
        stopPolling();
    }

    // ====== Submit modo URL ======
    const extractForm = $('#extract-form');
    const extractBtn = $('#btn-extract');

    extractForm.on('submit', function (e) {
        e.preventDefault();
        resetOutput(extractBtn);
        $.ajax({
            url: '{{ route('extractor.extract') }}',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            data: extractForm.serialize(),
            dataType: 'json'
        }).done(handleStartResponse).fail(handleStartFail);
    });

    // ====== Submit modo Certificado ======
    $('#cert-extr-file').on('change', function () {
        const file = this.files[0];
        $(this).next('.custom-file-label').text(file ? file.name : 'Seleccionar archivo...');
    });

    const certForm = $('#extract-cert-form');
    const certBtn = $('#btn-extract-cert');

    certForm.on('submit', function (e) {
        e.preventDefault();
        resetOutput(certBtn);
        const formData = new FormData(certForm[0]);
        $.ajax({
            url: '{{ route('extractor.extract-cert') }}',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json'
        }).done(handleStartResponse).fail(handleStartFail);
    });
});
</script>
@endpush
