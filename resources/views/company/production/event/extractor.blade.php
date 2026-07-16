@php
    $defaultFechaDesde = date('Y-m-01');
    $defaultFechaHasta = date('Y-m-d');
    $extractorConfig = [
        'url'     => config('services.extractor.url'),
        'has_key' => !empty(config('services.extractor.api_key')),
        'timeout' => config('services.extractor.timeout', 120),
    ];
    $extractorConfigured = !empty($extractorConfig['url']);
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
    // Escape para el atributo HTML5 pattern (regex de JavaScript, motor "v"):
    // solo se escapan los metacaracteres de JS. NO usar preg_quote, porque escapa
    // ":" y "-" con "\", que el motor "v" rechaza ("Invalid escape").
    $extractorCatalogBaseJs = preg_replace('/[.*+?^${}()|[\]\\\\]/', '\\\\$0', $extractorCatalogBase);
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
                <button type="button" id="evt-extr-btn-test"
                        class="btn btn-info text-white btn-sm"
                        @if(!$extractorConfigured) disabled title="EXTRACTOR_URL no está configurada en .env" @endif>
                    <i class="fas fa-plug mr-1"></i> Probar conexi&oacute;n
                </button>
            </div>
        </header>

        @if(!$extractorConfigured)
            <div class="alert alert-warning mb-3" role="alert" style="font-size:.95rem;">
                <strong><i class="fas fa-exclamation-triangle mr-1"></i> Extractor no configurado.</strong>
                Esta herramienta a&uacute;n no est&aacute; disponible para esta instalaci&oacute;n.
                El administrador debe definir la variable <code>EXTRACTOR_URL</code> en el archivo <code>.env</code>
                apuntando al servicio del extractor (p.&nbsp;ej. <code>http://localhost:3001</code> o
                <code>https://extractor.tu-dominio.com</code>) y luego correr
                <code>php artisan config:clear</code>. Mientras no se configure, las acciones de
                prueba y extracci&oacute;n permanecer&aacute;n deshabilitadas.
            </div>
        @else
            <div class="alert alert-info py-2 mb-3" style="font-size:.9rem;">
                <i class="fas fa-info-circle mr-1"></i>
                Consulta facturas <strong>recibidas</strong> en la DIAN y carga sus datos por CUFE
                para enviar eventos RADIAN sobre ellas.
            </div>
        @endif

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
                    <li class="nav-item" role="presentation">
                        <a class="nav-link" id="evt-tab-report" data-toggle="tab" href="#evt-pane-report" role="tab">
                            <i class="fas fa-file-excel mr-1"></i> Reporte por CUFEs
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
                                           pattern="^{{ $extractorCatalogBaseJs }}User/AuthToken.*"
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
                            <button type="submit" id="evt-btn-extract" class="btn btn-primary"
                                    @if(!$extractorConfigured) disabled title="EXTRACTOR_URL no está configurada en .env" @endif>
                                <i class="fas fa-play mr-1"></i> Iniciar extracci&oacute;n
                            </button>
                            <span class="text-muted ml-2" style="font-size:.85rem;">
                                Esta operaci&oacute;n puede tardar varios minutos.
                            </span>
                        </form>
                    </div>

                    {{-- ================= Reporte por CUFEs ================= --}}
                    <div class="tab-pane fade" id="evt-pane-report" role="tabpanel">
                        <div class="alert alert-info py-2 mb-3" style="font-size:.9rem;">
                            <i class="fas fa-info-circle mr-1"></i>
                            Obtiene los <strong>CUFEs</strong> del rango (sin descargar ZIP) y luego consulta
                            cada uno en la DIAN para armar un reporte descargable en <strong>Excel</strong> o <strong>JSON</strong>,
                            con detalle de <strong>l&iacute;neas de producto e impuestos</strong>
                            (Excel de 2 hojas: <em>Resumen</em> y <em>L&iacute;neas</em>).
                        </div>

                        @if(!$extractorApiToken)
                            <div class="alert alert-warning py-2 mb-3" style="font-size:.9rem;">
                                <i class="fas fa-exclamation-triangle mr-1"></i>
                                La empresa <strong>{{ $company->identification_number }}</strong> no tiene
                                <code>api_token</code>; el enriquecimiento por CUFE requiere token. Genere uno antes de usar esta herramienta.
                            </div>
                        @endif

                        <form id="evt-report-form">
                            @csrf
                            <input type="hidden" name="tipo" value="Received">
                            <div class="row">
                                <div class="form-group col-md-12">
                                    <label for="evt-report-url">URL de consulta por Token DIAN</label>
                                    <input type="url" id="evt-report-url" name="url" class="form-control"
                                           placeholder="{{ $extractorCatalogBase }}User/AuthToken?..."
                                           pattern="^{{ $extractorCatalogBaseJs }}User/AuthToken.*"
                                           title="Debe ser un enlace User/AuthToken de DIAN {{ $extractorEnvName }} ({{ $extractorCatalogBase }}User/AuthToken?...)"
                                           required>
                                    <small class="form-text text-muted">
                                        Pega el enlace <code>User/AuthToken</code> emitido por DIAN.
                                    </small>
                                </div>
                                <div class="form-group col-md-6">
                                    <label for="evt-report-fecha-desde">Fecha desde</label>
                                    <input type="date" id="evt-report-fecha-desde" name="fechaDesde" class="form-control" value="{{ $defaultFechaDesde }}" required>
                                </div>
                                <div class="form-group col-md-6">
                                    <label for="evt-report-fecha-hasta">Fecha hasta</label>
                                    <input type="date" id="evt-report-fecha-hasta" name="fechaHasta" class="form-control" value="{{ $defaultFechaHasta }}" required>
                                </div>
                            </div>
                            <button type="submit" id="evt-btn-report" class="btn btn-success"
                                    @if(!$extractorConfigured || !$extractorApiToken) disabled title="Requiere EXTRACTOR_URL configurada y api_token de la empresa" @endif>
                                <i class="fas fa-file-excel mr-1"></i> Generar reporte por CUFEs
                            </button>
                            <span class="text-muted ml-2" style="font-size:.85rem;">
                                Consulta 1 vez la DIAN por cada CUFE; puede tardar seg&uacute;n el rango.
                            </span>
                        </form>

                        <div id="evt-report-output" class="mt-3" style="display:none;">
                            <div class="card mb-3">
                                <div class="card-header d-flex justify-content-between align-items-center py-2">
                                    <strong><i class="fas fa-tasks mr-1"></i> Progreso</strong>
                                    <span id="evt-report-badge" class="badge badge-secondary">Iniciando&hellip;</span>
                                </div>
                                <div class="card-body">
                                    <div id="evt-report-msg" class="mb-2 text-muted" style="font-size:.9rem;"></div>
                                    <div class="progress" style="height:20px;">
                                        <div id="evt-report-bar" class="progress-bar progress-bar-striped progress-bar-animated"
                                             role="progressbar" style="width:0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                                    </div>
                                    <div id="evt-report-counts" class="mt-2 small text-muted"></div>
                                </div>
                            </div>

                            <div id="evt-report-downloads" class="card mb-3" style="display:none;">
                                <div class="card-header py-2">
                                    <strong><i class="fas fa-download mr-1"></i> Descargar reporte</strong>
                                </div>
                                <div class="card-body py-2">
                                    <button type="button" id="evt-report-dl-excel" class="btn btn-success btn-sm mr-2">
                                        <i class="fas fa-file-excel mr-1"></i> Descargar Excel
                                    </button>
                                    <button type="button" id="evt-report-dl-json" class="btn btn-outline-secondary btn-sm">
                                        <i class="fas fa-file-code mr-1"></i> Descargar JSON
                                    </button>
                                </div>
                            </div>

                            <div id="evt-report-table-wrap" class="card mb-3" style="display:none;">
                                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><i class="fas fa-table mr-1"></i> Vista previa</strong>
                                        <span id="evt-report-count" class="badge badge-info ml-2">0</span>
                                        <small class="text-muted ml-2">Primeras filas del reporte.</small>
                                    </div>
                                    <button type="button" id="evt-report-register-all" class="btn btn-primary btn-sm" style="display:none;">
                                        <i class="fas fa-cloud-upload-alt mr-1"></i> Registrar v&aacute;lidos en Eventos RADIAN
                                    </button>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive" style="max-height:380px; overflow:auto;">
                                        <table class="table table-sm table-striped mb-0">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th>#</th>
                                                    <th>Tipo</th>
                                                    <th>Folio</th>
                                                    <th>Fecha</th>
                                                    <th>NIT Emisor</th>
                                                    <th>Emisor</th>
                                                    <th class="text-right">Total</th>
                                                    <th>Estado RADIAN</th>
                                                    <th class="text-center">Extracci&oacute;n</th>
                                                    <th class="text-center">Eventos RADIAN</th>
                                                </tr>
                                            </thead>
                                            <tbody id="evt-report-tbody"></tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
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

@push('scripts')
{{-- ============ Reporte por CUFEs (cufes -> load-document-by-cufe -> Excel/JSON) ============ --}}
<script>
$(function () {
    var apiToken   = @json($extractorApiToken);
    var companyNit = @json($company->identification_number);
    var cufesUrl   = '{{ route('extractor.cufes') }}';
    var jobUrlBase = '{{ url('/extractor/job') }}';   // + '/' + jobId (polling async)
    var enrichUrl  = '{{ url('/api/cufes/enrich') }}';
    var reportUrl  = '{{ url('/api/cufes/report') }}';
    var csrfToken  = $('meta[name="csrf-token"]').attr('content');

    // Tamaño de lote: cada CUFE hace 1 consulta SOAP a la DIAN, por eso se
    // procesan en lotes pequeños desde el navegador (sin queue worker).
    var BATCH_SIZE = 5;

    var $form      = $('#evt-report-form');
    var $btn       = $('#evt-btn-report');
    var $out       = $('#evt-report-output');
    var $badge     = $('#evt-report-badge');
    var $msg       = $('#evt-report-msg');
    var $bar       = $('#evt-report-bar');
    var $counts    = $('#evt-report-counts');
    var $downloads = $('#evt-report-downloads');
    var $tableWrap = $('#evt-report-table-wrap');
    var $tbody     = $('#evt-report-tbody');
    var $count     = $('#evt-report-count');
    var $dlExcel   = $('#evt-report-dl-excel');
    var $dlJson    = $('#evt-report-dl-json');
    var $regAll    = $('#evt-report-register-all');
    var loadCufeUrl = '{{ url('/api/load-document-by-cufe') }}';

    var accumulatedRows = [];
    var filenameBase = 'reporte_cufes';
    var btnOriginalHtml = $btn.html();

    function setBadge(status, textOverride) {
        var map = {
            starting: ['badge-secondary', '<i class="fas fa-hourglass-half mr-1"></i> Iniciando&hellip;'],
            cufes:    ['badge-primary',   '<i class="fas fa-spinner fa-spin mr-1"></i> Obteniendo CUFEs&hellip;'],
            enrich:   ['badge-primary',   '<i class="fas fa-spinner fa-spin mr-1"></i> Consultando DIAN&hellip;'],
            done:     ['badge-success',   '<i class="fas fa-check mr-1"></i> Completado'],
            empty:    ['badge-info',      '<i class="fas fa-info-circle mr-1"></i> Sin datos'],
            error:    ['badge-danger',    '<i class="fas fa-times mr-1"></i> Error']
        };
        var pair = map[status] || map.starting;
        $badge.removeClass(function (_, c) {
            return (c.match(/(^|\s)badge-\S+/g) || []).join(' ');
        }).addClass('badge ' + pair[0]).html(textOverride || pair[1]);
    }

    function setBar(pct, animated) {
        pct = Math.max(0, Math.min(100, Math.round(pct)));
        $bar.css('width', pct + '%').attr('aria-valuenow', pct).text(pct + '%');
        if (animated) {
            $bar.addClass('progress-bar-animated');
        } else {
            $bar.removeClass('progress-bar-animated');
        }
    }

    function fmtMoney(n) {
        if (n === null || n === undefined || n === '') return '';
        var num = Number(n);
        if (isNaN(num)) return String(n);
        return num.toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function statusBadgeHtml(st) {
        if (st === 'ok')       return '<span class="badge badge-success">OK</span>';
        if (st === 'mismatch') return '<span class="badge badge-warning">No coincide</span>';
        return '<span class="badge badge-danger">Error</span>';
    }

    function updateCounts(done, total) {
        var ok = 0, mm = 0, er = 0;
        accumulatedRows.forEach(function (r) {
            if (r.enrich_status === 'ok') ok++;
            else if (r.enrich_status === 'mismatch') mm++;
            else er++;
        });
        $counts.html('Procesados: <strong>' + done + '/' + total + '</strong> &middot; '
            + 'OK: <strong>' + ok + '</strong> &middot; '
            + 'No coincide: <strong>' + mm + '</strong> &middot; '
            + 'Error: <strong>' + er + '</strong>');
    }

    function actionCell(r, i) {
        var $td = $('<td>').addClass('text-center');
        var cufe = r.cufe || (r.dian && r.dian.cufe) || '';
        if (!apiToken) {
            $td.html('<span class="text-muted small">Sin token</span>');
        } else if (r.registered) {
            $td.html('<span class="badge badge-success"><i class="fas fa-check mr-1"></i>Registrado</span>');
        } else if (r.already_registered) {
            $td.html('<span class="badge badge-secondary"><i class="fas fa-check mr-1"></i>Ya en listado</span>');
        } else if (r.enrich_status === 'ok' && cufe) {
            $td.append($('<button>').attr('type', 'button')
                .addClass('btn btn-sm btn-outline-primary evt-report-reg-btn')
                .attr('data-idx', i).attr('data-cufe', cufe)
                .html('<i class="fas fa-plus mr-1"></i> Registrar'));
        } else {
            $td.html('<span class="text-muted">&mdash;</span>');
        }
        return $td;
    }

    function renderPreview() {
        $tbody.empty();
        var limit = Math.min(accumulatedRows.length, 200);
        for (var i = 0; i < limit; i++) {
            var r = accumulatedRows[i];
            var dian = r.dian || {};
            var $tr = $('<tr>');
            $tr.append($('<td>').text(i + 1));
            $tr.append($('<td>').text(r.tipoDoc || dian.type_document_id || ''));
            $tr.append($('<td>').text(r.folio || dian.number || ''));
            $tr.append($('<td>').text(r.fechaEmision || dian.date_issue || ''));
            $tr.append($('<td>').text(r.nitEmisor || dian.identification_number || ''));
            $tr.append($('<td>').text(r.nombreEmisor || dian.name_seller || ''));
            $tr.append($('<td>').addClass('text-right').text(fmtMoney(dian.total)));
            $tr.append($('<td>').text(r.EstadoRadian || ''));
            $tr.append($('<td>').addClass('text-center')
                .attr('title', r.enrich_message || '')
                .html(statusBadgeHtml(r.enrich_status)));
            $tr.append(actionCell(r, i));
            $tbody.append($tr);
        }
        $count.text(accumulatedRows.length);
        $tableWrap.show();

        var pendingOk = accumulatedRows.filter(function (r) {
            return r.enrich_status === 'ok' && !r.registered && !r.already_registered;
        }).length;
        if (apiToken && pendingOk > 0) { $regAll.show(); } else { $regAll.hide(); }
    }

    function resetOutput() {
        accumulatedRows = [];
        $out.show();
        $msg.text('');
        setBadge('starting');
        setBar(0, true);
        $counts.html('');
        $downloads.hide();
        $tableWrap.hide();
        $tbody.empty();
        $count.text('0');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Procesando&hellip;');
    }

    function finishRun(status) {
        setBadge(status);
        setBar(100, false);
        $btn.prop('disabled', false).html(btnOriginalHtml);
    }

    // Enriquecer los items en lotes secuenciales.
    function enrichInBatches(items) {
        var total = items.length;
        var i = 0;
        setBadge('enrich');
        setBar(0, true);

        function next() {
            if (i >= total) {
                if (accumulatedRows.length === 0) {
                    finishRun('empty');
                    $msg.text('No se obtuvieron filas.');
                    return;
                }
                renderPreview();
                $downloads.show();
                finishRun('done');
                $msg.html('Reporte listo con <strong>' + accumulatedRows.length + '</strong> documento(s). Descargue en Excel o JSON.');
                return;
            }

            var batch = items.slice(i, i + BATCH_SIZE);
            $.ajax({
                url: enrichUrl,
                method: 'POST',
                headers: { 'Authorization': 'Bearer ' + apiToken, 'Accept': 'application/json' },
                contentType: 'application/json',
                data: JSON.stringify({ items: batch })
            }).done(function (resp) {
                if (resp && resp.success && Array.isArray(resp.rows)) {
                    accumulatedRows = accumulatedRows.concat(resp.rows);
                } else {
                    // El endpoint respondió pero sin filas: marcar el lote como error.
                    var m = (resp && resp.message) ? resp.message : 'Respuesta inesperada del enriquecimiento.';
                    batch.forEach(function (it) {
                        var r = ($.isPlainObject(it)) ? $.extend({}, it) : { cufe: it };
                        r.dian = null; r.enrich_status = 'error'; r.enrich_message = m;
                        accumulatedRows.push(r);
                    });
                }
                i += batch.length;
                updateCounts(i, total);
                setBar(i / total * 100, true);
                next();
            }).fail(function (xhr) {
                var resp = xhr.responseJSON || {};
                var m = resp.message || ('HTTP ' + xhr.status);
                batch.forEach(function (it) {
                    var r = ($.isPlainObject(it)) ? $.extend({}, it) : { cufe: it };
                    r.dian = null; r.enrich_status = 'error'; r.enrich_message = m;
                    accumulatedRows.push(r);
                });
                i += batch.length;
                updateCounts(i, total);
                setBar(i / total * 100, true);
                next();
            });
        }

        next();
    }

    $form.on('submit', function (e) {
        e.preventDefault();

        if (!apiToken) {
            alert('La empresa ' + companyNit + ' no tiene api_token configurado.');
            return;
        }

        var desde = $('#evt-report-fecha-desde').val();
        var hasta = $('#evt-report-fecha-hasta').val();
        filenameBase = 'reporte_cufes_' + desde + '_' + hasta;

        resetOutput();
        setBadge('cufes');
        $msg.text('Iniciando extracción de CUFEs en el extractor…');

        // Paso 1: iniciar el job async en el extractor (respuesta inmediata con jobId).
        // El extractor arranca Chrome headful (~60-90s) — por eso NO se espera la
        // respuesta síncrona (daría 504 en el proxy): se hace polling del job.
        $.ajax({
            url: cufesUrl,
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            data: $form.serialize(),
            dataType: 'json'
        }).done(function (resp) {
            if (!resp || !resp.jobId) {
                finishRun('error');
                $msg.text((resp && resp.message) ? resp.message : 'El extractor no devolvió un jobId.');
                return;
            }
            pollCufesJob(resp.jobId);
        }).fail(function (xhr) {
            var resp = xhr.responseJSON || {};
            var errors = resp.errors ? Object.values(resp.errors).flat().join(' · ') : '';
            finishRun('error');
            $msg.text((resp.message || 'HTTP ' + xhr.status) + (errors ? ' — ' + errors : ''));
        });
    });

    // Polling del job de CUFEs hasta status 'done'/'error'. En la DIAN esto tarda
    // porque Chrome corre headful bajo Xvfb para evitar el WAF anti-bot.
    function pollCufesJob(jobId) {
        var POLL_MS = 3000;      // cada 3s
        var MAX_TRIES = 200;     // ~10 min de techo
        var tries = 0;
        var startedAt = Date.now();

        (function tick() {
            tries++;
            $.ajax({
                url: jobUrlBase + '/' + encodeURIComponent(jobId),
                method: 'GET',
                headers: { 'X-CSRF-TOKEN': csrfToken },
                dataType: 'json'
            }).done(function (resp) {
                var status = resp && resp.status;

                if (status === 'running') {
                    if (tries >= MAX_TRIES) {
                        finishRun('error');
                        $msg.text('La extracción tardó demasiado (timeout de espera). Revisa el extractor.');
                        return;
                    }
                    var secs = Math.round((Date.now() - startedAt) / 1000);
                    $msg.html('Extrayendo CUFEs del catálogo DIAN&hellip; <small class="text-muted">(' + secs + 's)</small>');
                    setTimeout(tick, POLL_MS);
                    return;
                }

                // status 'done' o 'error' (partial => 'done' con datos parciales)
                var items = (resp && Array.isArray(resp.data)) ? resp.data : [];
                if (!items.length) {
                    var errMsg = resp && (resp.error || resp.message);
                    if (status === 'error') {
                        finishRun('error');
                        $msg.text(errMsg || 'El extractor terminó con error y sin CUFEs.');
                    } else {
                        finishRun('empty');
                        $msg.text(errMsg || 'Sin CUFEs en el rango de fechas.');
                    }
                    return;
                }
                var extra = resp.partial ? ' <span class="text-warning">(resultado parcial)</span>' : '';
                $msg.html('Se obtuvieron <strong>' + items.length + '</strong> CUFE(s)' + extra + '. Consultando la DIAN por cada uno&hellip;');
                enrichInBatches(items);
            }).fail(function (xhr) {
                var resp = xhr.responseJSON || {};
                // 404 del job (expirado) o error de red: cortar el polling.
                finishRun('error');
                $msg.text(resp.message || ('Error consultando el estado del job (HTTP ' + xhr.status + ').'));
            });
        })();
    }

    // Descargas: POST de las filas acumuladas -> blob -> descarga
    function downloadReport(format) {
        if (!accumulatedRows.length) return;
        var $b = (format === 'json') ? $dlJson : $dlExcel;
        var orig = $b.html();
        $b.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Generando&hellip;');

        fetch(reportUrl, {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + apiToken,
                'Accept': (format === 'json') ? 'application/json' : 'application/octet-stream',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ rows: accumulatedRows, format: format, filenameBase: filenameBase })
        }).then(function (res) {
            if (!res.ok) {
                // Si el backend devolvió un JSON de error (p.ej. falta PhpSpreadsheet),
                // mostrar su mensaje en vez de un genérico "HTTP 422".
                return res.json().then(function (j) {
                    throw new Error((j && j.message) ? j.message : ('HTTP ' + res.status));
                }, function () { throw new Error('HTTP ' + res.status); });
            }
            return res.blob();
        }).then(function (blob) {
            var objectUrl = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = objectUrl;
            a.download = filenameBase + (format === 'json' ? '.json' : '.xlsx');
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(objectUrl);
            $b.prop('disabled', false).html(orig);
        }).catch(function (err) {
            $b.prop('disabled', false).html(orig);
            alert('No se pudo generar el reporte: ' + err.message);
        });
    }

    $dlExcel.on('click', function () { downloadReport('xlsx'); });
    $dlJson.on('click', function () { downloadReport('json'); });

    // ---- Registrar en Eventos RADIAN (received_documents vía load-document-by-cufe) ----
    // El envío del evento NO se hace aquí: se registra el documento en el listado
    // y desde la pestaña "Listado" de Eventos RADIAN se envían los eventos.
    function registerCufe(cufe, $btn, idx, cb) {
        if (!cufe || !apiToken) { if (cb) cb(false); return; }
        if ($btn) $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Registrando&hellip;');
        $.ajax({
            url: loadCufeUrl,
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + apiToken, 'Accept': 'application/json' },
            data: { cufe: cufe, save: true }
        }).done(function (resp) {
            if (resp && resp.success) {
                if (idx != null && accumulatedRows[idx]) {
                    if (resp.already_exists) accumulatedRows[idx].already_registered = true;
                    else accumulatedRows[idx].registered = true;
                }
                if ($btn) {
                    var lbl = resp.already_exists
                        ? '<span class="badge badge-secondary"><i class="fas fa-check mr-1"></i>Ya en listado</span>'
                        : '<span class="badge badge-success"><i class="fas fa-check mr-1"></i>Registrado</span>';
                    $btn.closest('td').html(lbl);
                }
                if (cb) cb(true);
            } else {
                var msg = (resp && resp.message) ? resp.message : 'No se pudo registrar.';
                if ($btn) $btn.prop('disabled', false).attr('title', msg)
                    .html('<i class="fas fa-exclamation-triangle mr-1"></i> Reintentar');
                if (cb) cb(false, msg);
            }
        }).fail(function (xhr) {
            var resp = xhr.responseJSON || {};
            var msg = resp.message || ('HTTP ' + xhr.status);
            if ($btn) $btn.prop('disabled', false).attr('title', msg)
                .html('<i class="fas fa-times mr-1"></i> Error');
            if (cb) cb(false, msg);
        });
    }

    // Registro individual (delegación de eventos sobre la tabla)
    $tbody.on('click', '.evt-report-reg-btn', function () {
        var $b = $(this);
        registerCufe($b.data('cufe'), $b, parseInt($b.data('idx'), 10));
    });

    // Registro masivo de todos los válidos (enrich_status = ok, no registrados)
    $regAll.on('click', function () {
        var pending = [];
        accumulatedRows.forEach(function (r, idx) {
            var cufe = r.cufe || (r.dian && r.dian.cufe);
            if (r.enrich_status === 'ok' && !r.registered && !r.already_registered && cufe) {
                pending.push(idx);
            }
        });
        if (!pending.length) { alert('No hay documentos válidos pendientes por registrar.'); return; }

        $regAll.prop('disabled', true);
        var okc = 0;
        function step(k) {
            if (k >= pending.length) {
                $regAll.prop('disabled', false).html('<i class="fas fa-cloud-upload-alt mr-1"></i> Registrar v&aacute;lidos en Eventos RADIAN');
                renderPreview();
                $msg.html('Registrados <strong>' + okc + '</strong> de <strong>' + pending.length
                    + '</strong> en Eventos RADIAN. Ve a la pesta&ntilde;a <strong>Listado</strong> para enviar los eventos.');
                return;
            }
            var idx = pending[k];
            var cufe = accumulatedRows[idx].cufe || (accumulatedRows[idx].dian && accumulatedRows[idx].dian.cufe);
            var $b = $tbody.find('.evt-report-reg-btn[data-idx="' + idx + '"]');
            $regAll.html('<i class="fas fa-spinner fa-spin mr-1"></i> Registrando ' + (k + 1) + '/' + pending.length + '&hellip;');
            registerCufe(cufe, $b.length ? $b : null, idx, function (ok) {
                if (ok) okc++;
                step(k + 1);
            });
        }
        step(0);
    });
});
</script>
@endpush
