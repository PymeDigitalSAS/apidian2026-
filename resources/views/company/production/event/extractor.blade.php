@php
    $defaultFechaDesde = date('Y-m-01');
    $defaultFechaHasta = date('Y-m-d');
    $extractorConfig = [
        'url'     => config('services.extractor.url'),
        'has_key' => !empty(config('services.extractor.api_key')),
        'timeout' => config('services.extractor.timeout', 120),
    ];
    $extractorApiToken = $company->user->api_token ?? null;

    // Ambiente del evento RADIAN — controla las URLs DIAN que usar&aacute; el extractor.
    // 1 = Producci&oacute;n, 2 = Habilitaci&oacute;n.
    $extractorEnvId   = isset($environmentStatus['environment_id'])
        ? (int) $environmentStatus['environment_id']
        : (int) ($company->event_type_environment_id ?? 2);
    $extractorIsProd  = $extractorEnvId === 1;
    $extractorEnvName = $extractorIsProd ? 'Producci&oacute;n' : 'Habilitaci&oacute;n';
    $extractorEnvBadge = $extractorIsProd ? 'bg-success' : 'bg-warning';
    $extractorCertUrl  = $extractorIsProd
        ? 'https://certificate-vpfe.dian.gov.co'
        : 'https://certificate-vpfe-hab.dian.gov.co/';
    $extractorCatalogBase = $extractorIsProd
        ? 'https://catalogo-vpfe.dian.gov.co/'
        : 'https://catalogo-vpfe-hab.dian.gov.co/';
@endphp

{{-- Toast flotante para la acción "Cargar por CUFE" --}}
<div id="evt-cufe-toast" style="display:none; position:fixed; top:20px; right:20px; z-index:9999; min-width:320px; max-width:520px;">
    <div id="evt-cufe-toast-alert" class="alert alert-dismissible fade show mb-0" role="alert" style="box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
        <strong><i id="evt-cufe-toast-icon" class="fas fa-info-circle"></i> <span id="evt-cufe-toast-title">Aviso</span></strong>
        <div id="evt-cufe-toast-msg" style="margin-top:5px;"></div>
        <div id="evt-cufe-toast-actions" style="margin-top:8px;"></div>
        <button type="button" class="close" aria-label="Cerrar" onclick="document.getElementById('evt-cufe-toast').style.display='none'">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
</div>

<div class="row mt-3">
    <div class="col-md-12">
        <header class="page-header d-flex justify-content-between align-items-center mb-2">
            <div>
                <h2>{{ $company->user->name }} - {{ $company->identification_number }}</h2>
                <br>
                <span class="text-muted">Eventos RADIAN &mdash; Extractor de facturas</span>
                <span class="badge {{ $extractorEnvBadge }} ms-2" style="font-size:.7rem; vertical-align:middle;">
                    Ambiente: {!! $extractorEnvName !!}
                </span>
            </div>
            <div class="right-wrapper text-end mt-auto pb-1">
                <button type="button" id="evt-extr-btn-test" class="btn btn-info text-white btn-sm">
                    <i class="fas fa-plug mr-1"></i> Probar conexi&oacute;n
                </button>
            </div>
        </header>

        <div class="alert alert-info py-2 mb-3" style="font-size:.9rem;">
            <i class="fas fa-info-circle mr-1"></i>
            Consulta facturas <strong>recibidas</strong> en la DIAN y carga sus datos por CUFE
            para enviar eventos RADIAN sobre ellas.
        </div>

        <div id="evt-extr-conn-result" class="mt-2 mb-3" style="display:none;"></div>

        <div class="card mb-3">
            <div class="card-header py-2">
                <strong><i class="fas fa-cloud-download-alt mr-1"></i> Solicitar extracci&oacute;n</strong>
            </div>
            <div class="card-body">
                <ul class="nav nav-tabs mb-3" id="evtExtractorTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <a class="nav-link active" id="evt-tab-url" data-toggle="tab" href="#evt-pane-url" role="tab">
                            <i class="fas fa-link mr-1"></i> Consulta por Token
                        </a>
                    </li>
                    {{-- Sub-tab "Por Certificado" oculto: aún no disponible. --}}
                    <li class="nav-item" role="presentation" style="display:none;">
                        <a class="nav-link" id="evt-tab-cert" data-toggle="tab" href="#evt-pane-cert" role="tab">
                            <i class="fas fa-shield-alt mr-1"></i> Por Certificado (.p12)
                            <span class="badge badge-warning ml-1">pruebas</span>
                        </a>
                    </li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade show active" id="evt-pane-url" role="tabpanel">
                        <form id="evt-extract-form">
                            @csrf
                            <input type="hidden" name="tipo" value="Received">
                            <div class="row">
                                <div class="form-group col-md-12">
                                    <label for="evt-extr-url">URL de consulta por Token DIAN</label>
                                    <input type="url" id="evt-extr-url" name="url" class="form-control"
                                           placeholder="{{ $extractorCatalogBase }}User/AuthToken?..."
                                           pattern="^{{ preg_quote($extractorCatalogBase, '/') }}User/AuthToken.*"
                                           title="Debe ser un enlace User/AuthToken de DIAN {{ $extractorEnvName }} ({{ $extractorCatalogBase }}User/AuthToken?...)"
                                           required>
                                    <small class="form-text text-muted">
                                        Pega el enlace <code>User/AuthToken</code> emitido por DIAN.
                                    </small>
                                </div>
                                <div class="form-group col-md-6">
                                    <label for="evt-extr-fecha-desde">Fecha desde</label>
                                    <input type="date" id="evt-extr-fecha-desde" name="fechaDesde" class="form-control" value="{{ $defaultFechaDesde }}" required>
                                </div>
                                <div class="form-group col-md-6">
                                    <label for="evt-extr-fecha-hasta">Fecha hasta</label>
                                    <input type="date" id="evt-extr-fecha-hasta" name="fechaHasta" class="form-control" value="{{ $defaultFechaHasta }}" required>
                                </div>
                            </div>
                            <button type="submit" id="evt-btn-extract" class="btn btn-primary">
                                <i class="fas fa-play mr-1"></i> Iniciar extracci&oacute;n
                            </button>
                            <span class="text-muted ml-2" style="font-size:.85rem;">
                                Esta operaci&oacute;n puede tardar varios minutos.
                            </span>
                        </form>
                    </div>

                    <div class="tab-pane fade" id="evt-pane-cert" role="tabpanel" style="display:none;">
                        <div class="alert alert-warning py-2 mb-3" style="font-size:.85rem;">
                            <i class="fas fa-flask mr-1"></i>
                            <strong>Modo pruebas.</strong>
                            Si subes un <code>.p12</code> y su contrase&ntilde;a, el extractor har&aacute; mTLS contra DIAN.
                            Si los dejas vac&iacute;os, abrir&aacute; un Chrome persistente sobre la URL elegida y
                            <strong>reutilizar&aacute; la sesi&oacute;n DIAN iniciada manualmente</strong> (cookies en memoria).
                        </div>
                        <form id="evt-extract-cert-form" enctype="multipart/form-data">
                            @csrf
                            <input type="hidden" name="tipo" value="Received">
                            <div class="row">
                                <div class="form-group col-md-12">
                                    <label for="evt-cert-url">Ambiente DIAN</label>
                                    <input type="text" id="evt-cert-url" name="url" class="form-control"
                                           value="{{ $extractorCertUrl }}" readonly>
                                    <small class="form-text text-muted">
                                        Ambiente fijado por la configuraci&oacute;n RADIAN de la empresa:
                                        <strong>{!! $extractorEnvName !!}</strong>.
                                        Para cambiarlo, edita el sub-tab <em>Paso a Producci&oacute;n</em> de Eventos RADIAN.
                                    </small>
                                </div>
                                <div class="form-group col-md-6">
                                    <label for="evt-cert-fecha-desde">Fecha desde</label>
                                    <input type="date" id="evt-cert-fecha-desde" name="fechaDesde" class="form-control" value="{{ $defaultFechaDesde }}" required>
                                </div>
                                <div class="form-group col-md-6">
                                    <label for="evt-cert-fecha-hasta">Fecha hasta</label>
                                    <input type="date" id="evt-cert-fecha-hasta" name="fechaHasta" class="form-control" value="{{ $defaultFechaHasta }}" required>
                                </div>
                                <div class="form-group col-md-6">
                                    <label for="evt-cert-file">
                                        Archivo de certificado (.p12 / .pfx)
                                        <small class="text-muted">(opcional)</small>
                                    </label>
                                    <div class="custom-file">
                                        <input type="file" class="custom-file-input" id="evt-cert-file" name="certificate" accept=".p12,.pfx">
                                        <label class="custom-file-label" for="evt-cert-file">Seleccionar archivo...</label>
                                    </div>
                                </div>
                                <div class="form-group col-md-6">
                                    <label for="evt-cert-password">
                                        Contrase&ntilde;a del certificado
                                        <small class="text-muted">(opcional)</small>
                                    </label>
                                    <input type="password" id="evt-cert-password" name="p12Password" class="form-control" autocomplete="new-password">
                                </div>
                                <div class="form-group col-md-6">
                                    <label for="evt-cert-usercode">User code <small class="text-muted">(opcional)</small></label>
                                    <input type="text" id="evt-cert-usercode" name="userCode" class="form-control" placeholder="NIT u otro identificador">
                                </div>
                            </div>
                            <button type="submit" id="evt-btn-extract-cert" class="btn btn-warning">
                                <i class="fas fa-shield-alt mr-1"></i> Iniciar extracci&oacute;n
                            </button>
                            <span class="text-muted ml-2" style="font-size:.85rem;">
                                Sin certificado: la primera ejecuci&oacute;n abrir&aacute; Chrome para que inicies sesi&oacute;n; las siguientes la reutilizan.
                            </span>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div id="evt-extractor-output" class="mt-3" style="display:none;">
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center py-2">
                    <strong><i class="fas fa-terminal mr-1"></i> Progreso en vivo</strong>
                    <span id="evt-job-status-badge" class="badge badge-secondary">Iniciando&hellip;</span>
                </div>
                <div class="card-body p-0">
                    <div id="evt-job-log" style="background:#1e1e1e;color:#dcdcdc;font-family:Consolas,Menlo,monospace;font-size:.82rem;padding:12px;max-height:280px;overflow-y:auto;white-space:pre-wrap;"></div>
                </div>
                <div class="card-footer text-muted py-1" style="font-size:.8rem;">
                    Job ID: <code id="evt-job-id-display">&mdash;</code>
                </div>
            </div>

            <div id="evt-job-summary" class="mb-3" style="display:none;"></div>

            <div id="evt-job-downloads" class="mb-3" style="display:none;">
                <div class="card">
                    <div class="card-header py-2">
                        <strong><i class="fas fa-download mr-1"></i> Archivos generados por el extractor</strong>
                    </div>
                    <div class="card-body py-2">
                        <a id="evt-btn-download-excel" href="#" class="btn btn-success btn-sm mr-2" style="display:none;" target="_blank">
                            <i class="fas fa-file-excel mr-1"></i> Descargar Excel
                        </a>
                        <a id="evt-btn-download-json" href="#" class="btn btn-outline-secondary btn-sm" style="display:none;" target="_blank">
                            <i class="fas fa-file-code mr-1"></i> Descargar JSON
                        </a>
                    </div>
                </div>
            </div>

            <div id="evt-job-data-section" class="card mb-3" style="display:none;">
                <div class="card-header py-2">
                    <strong><i class="fas fa-file-invoice mr-1"></i> Documentos extra&iacute;dos</strong>
                    <span id="evt-job-data-count" class="badge badge-info ml-2">0</span>
                    <small class="text-muted ml-2">Solo se permite cargar facturas cuyo adquirente sea NIT <strong>{{ $company->identification_number }}</strong>.</small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>#</th>
                                    <th>Tipo</th>
                                    <th>Numero</th>
                                    <th>Fecha</th>
                                    <th>NIT Emisor</th>
                                    <th>Raz&oacute;n social Emisor</th>
                                    <th class="text-right">Total</th>
                                    <th>CUFE</th>
                                    <th class="text-center">Acci&oacute;n</th>
                                </tr>
                            </thead>
                            <tbody id="evt-job-data-tbody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function () {
    var apiToken    = @json($extractorApiToken);
    var companyNit  = @json($company->identification_number);
    var testUrl     = '{{ route('extractor.test') }}';
    var extractUrl  = '{{ route('extractor.extract') }}';
    var extractCert = '{{ route('extractor.extract-cert') }}';
    var jobUrlTpl   = '{{ url('extractor/job') }}/';
    var downloadTpl = '{{ url('extractor/download') }}/';
    var loadCufeUrl = '{{ url('/api/load-document-by-cufe') }}';

    var $testBtn  = $('#evt-extr-btn-test');
    var $testRes  = $('#evt-extr-conn-result');
    var $outBox   = $('#evt-extractor-output');
    var $logBox   = $('#evt-job-log');
    var $badge    = $('#evt-job-status-badge');
    var $jobId    = $('#evt-job-id-display');
    var $summary  = $('#evt-job-summary');
    var $dataSec  = $('#evt-job-data-section');
    var $dataCnt  = $('#evt-job-data-count');
    var $dataTb   = $('#evt-job-data-tbody');
    var $dlSec    = $('#evt-job-downloads');
    var $dlExcel  = $('#evt-btn-download-excel');
    var $dlJson   = $('#evt-btn-download-json');

    var pollHandle = null;
    var renderedLogLines = 0;
    var activeButton = null;
    var activeButtonHtml = null;
    var inProgress = false;

    function setBadge(status) {
        var map = {
            running:  ['badge-primary', '<i class="fas fa-spinner fa-spin mr-1"></i> En ejecuci&oacute;n'],
            done:     ['badge-success', '<i class="fas fa-check mr-1"></i> Completado'],
            partial:  ['badge-warning', '<i class="fas fa-exclamation-triangle mr-1"></i> Parcial'],
            error:    ['badge-danger',  '<i class="fas fa-times mr-1"></i> Error'],
            starting: ['badge-secondary', '<i class="fas fa-hourglass-half mr-1"></i> Iniciando&hellip;']
        };
        var pair = map[status] || map.starting;
        $badge.removeClass(function (_, c) {
            return (c.match(/(^|\s)badge-\S+/g) || []).join(' ');
        }).addClass('badge ' + pair[0]).html(pair[1]);
    }

    function appendLogLines(lines) {
        if (!Array.isArray(lines)) return;
        var fresh = lines.slice(renderedLogLines);
        if (!fresh.length) return;
        var html = fresh.map(function (line) { return $('<div>').text(line).html(); }).join('\n') + '\n';
        $logBox.append(html);
        renderedLogLines = lines.length;
        $logBox.scrollTop($logBox[0].scrollHeight);
    }

    function fmtMoney(n) {
        if (n === null || n === undefined || n === '') return '';
        var num = Number(n);
        if (isNaN(num)) return String(n);
        return num.toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function renderData(docs) {
        $dataCnt.text(docs.length);
        $dataTb.empty();
        docs.forEach(function (d, i) {
            var cufe = d['CUFE'] || '';
            var cufeShort = cufe ? cufe.slice(0, 12) + '&hellip;' : '';
            var $row = $('<tr>');
            $row.append($('<td>').text(i + 1));
            $row.append($('<td>').text(d['TipoDocumento'] || ''));
            $row.append($('<td>').text(d['Numero de Factura'] || d['Prefijo'] || ''));
            $row.append($('<td>').text(d['Fecha de Creaci&oacute;n'] || d['Fecha de Creación'] || ''));
            $row.append($('<td>').text(d['NIT del Emisor'] || ''));
            $row.append($('<td>').text(d['Razon Social del Emisor'] || ''));
            $row.append($('<td>').addClass('text-right').text(fmtMoney(d['Total a Pagar'])));
            $row.append($('<td>').attr('title', cufe).append($('<code>').html(cufeShort)));

            var $btn = $('<button>')
                .attr('type', 'button')
                .addClass('btn btn-sm btn-primary evt-load-cufe-btn')
                .attr('data-cufe', cufe)
                .prop('disabled', !cufe || !apiToken)
                .html('<i class="fas fa-download mr-1"></i> Cargar por CUFE');

            var $actionCell = $('<td>').addClass('text-center').append($btn);
            if (!cufe) {
                $actionCell.append($('<div>').addClass('text-muted small').text('Sin CUFE en el JSON'));
            } else if (!apiToken) {
                $actionCell.append($('<div>').addClass('text-danger small').text('Empresa sin api_token'));
            }
            $row.append($actionCell);
            $dataTb.append($row);
        });
        $dataSec.show();
    }

    function showSummary(level, message) {
        var map = {
            success: ['alert-success', 'fa-check-circle'],
            info:    ['alert-info',    'fa-info-circle'],
            warning: ['alert-warning', 'fa-exclamation-triangle'],
            danger:  ['alert-danger',  'fa-times-circle']
        };
        var pair = map[level] || map.info;
        $summary.html('<div class="alert ' + pair[0] + ' mb-0"><i class="fas ' + pair[1] + ' mr-1"></i>' + message + '</div>').show();
    }

    function stopPolling() {
        if (pollHandle) { clearTimeout(pollHandle); pollHandle = null; }
        if (activeButton) {
            activeButton.prop('disabled', false).html(activeButtonHtml);
            activeButton = null;
        }
        inProgress = false;
    }

    function setupDownloadsFromLog(logLines, folder) {
        if (!Array.isArray(logLines)) return;
        var excelRel = null, jsonRel = null;
        logLines.forEach(function (line) {
            var mE = line.match(/^EXCEL_PATH:\s*(.+)$/);
            if (mE) excelRel = mE[1].trim();
            var mJ = line.match(/^JSON_PATH:\s*(.+)$/);
            if (mJ) jsonRel = mJ[1].trim();
        });
        if (jsonRel && folder && jsonRel.indexOf('/') === -1 && jsonRel.indexOf('\\') === -1) {
            jsonRel = folder + '/' + jsonRel;
        }
        var shown = false;
        if (excelRel) {
            $dlExcel.attr('href', downloadTpl + excelRel.split('/').map(encodeURIComponent).join('/')).show();
            shown = true;
        }
        if (jsonRel) {
            $dlJson.attr('href', downloadTpl + jsonRel.split('/').map(encodeURIComponent).join('/')).show();
            shown = true;
        }
        if (shown) $dlSec.show();
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
                pollHandle = setTimeout(function () { pollJob(jobId); }, 1500);
                return;
            }

            var docs = Array.isArray(resp.data) ? resp.data : [];
            if (resp.status === 'done') {
                if (resp.partial) {
                    setBadge('partial');
                    showSummary('warning', 'Extracci&oacute;n parcial. Documentos: <strong>' + docs.length + '</strong>.');
                } else if (docs.length === 0) {
                    setBadge('done');
                    showSummary('info', 'Sin documentos en el rango de fechas.');
                } else {
                    setBadge('done');
                    showSummary('success', 'Extracci&oacute;n completada. Documentos: <strong>' + docs.length + '</strong>.');
                }
                if (docs.length) {
                    renderData(docs);
                    setupDownloadsFromLog(resp.log, resp.folder);
                }
            } else {
                setBadge('error');
                showSummary('danger', 'Error en la extracci&oacute;n: ' + (resp.error || 'desconocido'));
            }
            stopPolling();
        }).fail(function (xhr) {
            var resp = xhr.responseJSON || {};
            showSummary('danger', 'Fallo al consultar el job: ' + (resp.message || 'HTTP ' + xhr.status));
            setBadge('error');
            stopPolling();
        });
    }

    function resetOutput($btn) {
        activeButton = $btn;
        activeButtonHtml = $btn.html();
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Extrayendo&hellip;');

        $outBox.show();
        $logBox.empty();
        renderedLogLines = 0;
        $summary.hide().empty();
        $dataSec.hide();
        $dataTb.empty();
        $dataCnt.text('0');
        $jobId.text('—');
        $dlSec.hide();
        $dlExcel.hide().attr('href', '#');
        $dlJson.hide().attr('href', '#');
        setBadge('starting');
        inProgress = true;
    }

    function handleStartResponse(resp) {
        if (!resp || !resp.jobId) {
            setBadge('error');
            showSummary('danger', 'El extractor no devolvi&oacute; un jobId.');
            stopPolling();
            return;
        }
        $jobId.text(resp.jobId);
        setBadge('running');
        pollJob(resp.jobId);
    }

    function handleStartFail(xhr) {
        var resp = xhr.responseJSON || {};
        var errors = resp.errors ? Object.values(resp.errors).flat().join(' &middot; ') : '';
        showSummary('danger', (resp.message || 'HTTP ' + xhr.status) + (errors ? ' &mdash; ' + errors : ''));
        setBadge('error');
        stopPolling();
    }

    // Probar conexion
    $testBtn.on('click', function () {
        var original = $testBtn.html();
        $testBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Probando...');
        $testRes.hide().empty();
        $.ajax({
            url: testUrl,
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            dataType: 'json'
        }).done(function (data) {
            $testRes.html('<div class="alert alert-success mb-0"><i class="fas fa-check-circle mr-1"></i><strong>Conexi&oacute;n exitosa</strong> &mdash; ' + (data.message || '') + '</div>').show();
        }).fail(function (xhr) {
            var resp = xhr.responseJSON || {};
            $testRes.html('<div class="alert alert-danger mb-0"><i class="fas fa-times-circle mr-1"></i><strong>Fall&oacute; la conexi&oacute;n</strong> &mdash; ' + (resp.message || 'HTTP ' + xhr.status) + '</div>').show();
        }).always(function () {
            $testBtn.prop('disabled', false).html(original);
        });
    });

    // Submit URL
    var $extractForm = $('#evt-extract-form');
    var $btnExtract  = $('#evt-btn-extract');
    $extractForm.on('submit', function (e) {
        e.preventDefault();
        resetOutput($btnExtract);
        $.ajax({
            url: extractUrl,
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            data: $extractForm.serialize(),
            dataType: 'json'
        }).done(handleStartResponse).fail(handleStartFail);
    });

    // Submit Cert
    $('#evt-cert-file').on('change', function () {
        var file = this.files[0];
        $(this).next('.custom-file-label').text(file ? file.name : 'Seleccionar archivo...');
    });
    var $certForm = $('#evt-extract-cert-form');
    var $btnCert  = $('#evt-btn-extract-cert');
    $certForm.on('submit', function (e) {
        e.preventDefault();
        resetOutput($btnCert);
        var fd = new FormData($certForm[0]);
        $.ajax({
            url: extractCert,
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json'
        }).done(handleStartResponse).fail(handleStartFail);
    });

    // Toast helper para la acci&oacute;n "Cargar por CUFE"
    var $cufeToast      = $('#evt-cufe-toast');
    var $cufeToastAlert = $('#evt-cufe-toast-alert');
    var $cufeToastIcon  = $('#evt-cufe-toast-icon');
    var $cufeToastTitle = $('#evt-cufe-toast-title');
    var $cufeToastMsg   = $('#evt-cufe-toast-msg');
    var $cufeToastActs  = $('#evt-cufe-toast-actions');
    var cufeToastTimer  = null;

    function showCufeToast(level, title, message) {
        var map = {
            success: ['alert-success', 'fas fa-check-circle'],
            info:    ['alert-info',    'fas fa-info-circle'],
            warning: ['alert-warning', 'fas fa-exclamation-triangle'],
            danger:  ['alert-danger',  'fas fa-times-circle']
        };
        var pair = map[level] || map.info;
        $cufeToastAlert.attr('class', 'alert alert-dismissible fade show mb-0 ' + pair[0]);
        $cufeToastIcon.attr('class', pair[1]);
        $cufeToastTitle.text(title || 'Aviso');
        $cufeToastMsg.text(message || '');
        $cufeToastActs.empty();
        $cufeToast.stop(true, true).show();
        if (cufeToastTimer) clearTimeout(cufeToastTimer);
        cufeToastTimer = setTimeout(function () { $cufeToast.fadeOut(400); }, 6000);
    }

    // Cargar por CUFE
    $dataTb.on('click', '.evt-load-cufe-btn', function () {
        var $btn = $(this);
        var cufe = $btn.data('cufe');
        if (!cufe) return;
        if (!apiToken) {
            showCufeToast('danger', 'Sin token', 'La empresa ' + companyNit + ' no tiene api_token configurado.');
            return;
        }
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Cargando...');

        $.ajax({
            url: loadCufeUrl,
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + apiToken,
                'Accept': 'application/json'
            },
            data: { cufe: cufe, save: true }
        }).done(function (resp) {
            if (resp && resp.success) {
                var label;
                if (resp.already_exists) {
                    label = '<i class="fas fa-check mr-1"></i> Ya procesado';
                    $btn.removeClass('btn-primary').addClass('btn-secondary').html(label);
                    showCufeToast('info', 'Documento ya cargado',
                        'Este documento ya se encontraba en el listado de Eventos RADIAN');
                } else if (resp.saved) {
                    label = '<i class="fas fa-check mr-1"></i> Cargado en listado';
                    $btn.removeClass('btn-primary').addClass('btn-success').html(label);
                    showCufeToast('success', 'Carga exitosa',
                        'El documento fue cargado correctamente en el listado de Eventos RADIAN');
                } else {
                    // success === true pero saved === false: el endpoint no persisti&oacute;.
                    label = '<i class="fas fa-check mr-1"></i> Cargado';
                    $btn.removeClass('btn-primary').addClass('btn-success').html(label);
                    showCufeToast('warning', 'Cargado sin persistir',
                        'Los datos del documento se obtuvieron pero no quedaron grabados en received_documents.');
                }
            } else {
                var msg = (resp && resp.message) ? resp.message : 'No se pudo cargar el documento.';
                $btn.removeClass('btn-primary').addClass('btn-warning')
                    .attr('title', msg)
                    .html('<i class="fas fa-exclamation-triangle mr-1"></i> ' + msg.substring(0, 40));
                $btn.prop('disabled', false);
                showCufeToast('danger', 'No se pudo cargar', msg);
            }
        }).fail(function (xhr) {
            var resp = xhr.responseJSON || {};
            var msg = resp.message || ('HTTP ' + xhr.status);
            $btn.removeClass('btn-primary').addClass('btn-danger')
                .attr('title', msg)
                .html('<i class="fas fa-times mr-1"></i> Error');
            $btn.prop('disabled', false);
            showCufeToast('danger', 'Error al cargar', msg);
        });
    });
});
</script>
@endpush
