{{-- Toast de notificación para descargas y acciones --}}
<div id="payroll-toast" style="display:none; position:fixed; top:20px; right:20px; z-index:9999; min-width:300px; max-width:450px;">
    <div id="payroll-toast-box" class="alert alert-dismissible fade show mb-0" role="alert" style="box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
        <span id="payroll-toast-icon"></span>
        <span id="payroll-toast-msg"></span>
        <button type="button" class="close" onclick="document.getElementById('payroll-toast').style.display='none'">
            <span>&times;</span>
        </button>
    </div>
</div>

@php
    // Filtro del panel de empresa (Documentos Electrónicos > Nómina). En el portal de
    // vendedores no se muestra (ahí va el buscador propio más abajo).
    $payrollIsCompany  = (Request::is('company*') || Request::is('companies*'));
    $payrollField      = request('payroll_search_field', 'number');
    $payrollSearchTerm = request('payroll_search');
    $payrollSearchTo   = request('payroll_search_to');
    $payrollHasSearch  = $payrollIsCompany && (filled($payrollSearchTerm) || ($payrollField === 'date' && filled($payrollSearchTo)));
    $payrollTotal      = method_exists($documents, 'total') ? $documents->total() : $documents->count();

    // Tipos de documento de nómina (Nómina Individual / de Ajuste).
    $payrollTypes = \App\TypeDocument::whereIn('id', [9, 10])->orderBy('id')->get()->pluck('name', 'id')->toArray();

    $payrollFields = [
        ['key' => 'number',   'label' => 'Número',      'control' => 'text'],
        ['key' => 'prefix',   'label' => 'Prefijo',     'control' => 'text'],
        ['key' => 'employee', 'label' => 'ID Empleado', 'control' => 'text'],
        ['key' => 'date',     'label' => 'Fecha',       'control' => 'date'],
    ];
    if (!empty($payrollTypes)) {
        $payrollFields[] = ['key' => 'type', 'label' => 'Tipo de documento', 'control' => 'select', 'options' => $payrollTypes];
    }

    // Texto legible del valor buscado (para el estado vacío).
    if ($payrollField === 'type') {
        $payrollDisplay = $payrollTypes[$payrollSearchTerm] ?? $payrollSearchTerm;
    } elseif ($payrollField === 'date') {
        $payrollDisplay = (filled($payrollSearchTerm) && filled($payrollSearchTo)) ? ($payrollSearchTerm.' a '.$payrollSearchTo) : ($payrollSearchTerm ?: $payrollSearchTo);
    } else {
        $payrollDisplay = $payrollSearchTerm;
    }
@endphp

@if($payrollIsCompany)
    @include('partials.search.bar', [
        'searchPrefix' => 'payroll_',
        'fields'       => $payrollFields,
        'default'      => 'number',
        'total'        => $payrollTotal,
    ])
@endif

@if ($documents->isEmpty())
    @if($payrollHasSearch)
        <div class="text-muted text-center mt-4">No se encontraron documentos que coincidan con "<strong>{{ $payrollDisplay }}</strong>".</div>
    @else
        <div class="text-muted text-center mt-4">No hay documentos para mostrar.</div>
    @endif
@else
    @if(!(Request::is('company*') || Request::is('companies*')))
    <form method="GET" action="{{ url('/oksellerspayrollssearch/'.$company_idnumber) }}">
        <table class="table">
            <tr>
                <td>
                    <div>
                        <select id="searchfield" name="searchfield" class="browser-default custom-select">
                            <option selected="">Seleccione campo para filtrar.</option>
                            <option value="9">Nomina Individual: Numero</option>
                            <option value="10">Nomina Individual de Ajuste: Numero</option>
                            <option value="3">Fecha</option>
                            <option value="4">ID Empleado</option>
                            <option value="5">Prefijo</option>
                        </select>
                    </div>
                </td>
                <td>
                    <div>
                        <input id="searchvalue" type="text" class="form-control" name="searchvalue" autofocus>
                    </div>
                </td>
                <td>
                    <div>
                        <button type="submit" id="btnsearch" name="btnsearch" class="btn btn-primary">
                            Buscar
                        </button>
                    </div>
                </td>
            </tr>
        </table>
    </form>
    @endif
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead class="thead-light">
                <tr>
                    <th scope="col">Tipo Documento</th>
                    <th scope="col">Fecha</th>
                    <th scope="col">Prefijo</th>
                    <th scope="col">Numero</th>
                    <th scope="col">ID Empleado</th>
                    <th scope="col">Correo</th>
                    <th scope="col">XML</th>
                    <th scope="col">PDF</th>
                    <th scope="col">Enviar</th>
                    <th></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($documents as $document)
                    <tr class="table-light">
                        <td>{!! $document->type_document->name !!}</td>
                        <td>{!! $document->date_issue !!}</td>
                        <td>{!! $document->prefix !!}</td>
                        <td>{!! $document->consecutive !!}</td>
                        <td>{!! $document->employee_id !!}</td>
                        <td class="text-center">
                            @if($document->send_email_success)
                                <span class="badge badge-success" title="Enviado {{ $document->send_email_date_time }}">
                                    <i class="fas fa-check"></i> Enviado
                                </span>
                            @elseif($document->send_email_error)
                                <span class="badge badge-danger payroll-email-error" style="cursor:pointer;"
                                    data-error="{{ $document->send_email_error }}">
                                    <i class="fas fa-times"></i> Error
                                </span>
                            @else
                                <span class="badge badge-secondary" title="No se solicitó envío de correo">&mdash;</span>
                            @endif
                        </td>
                        @php
                            $allow_public_downloads = env("ALLOW_PUBLIC_DOWNLOAD", true)
                        @endphp
                        @if($allow_public_downloads)
                            <td><a href="#" onclick="downloadPayrollFile('{{ url('/api/download/'.$company_idnumber.'/'.$document->xml) }}'); return false;"><i class="fa fa-download"></i></a></td>
                            <td><a href="#" onclick="downloadPayrollFile('{{ url('/api/download/'.$company_idnumber.'/'.$document->pdf) }}'); return false;"><i class="fa fa-download"></i></a></td>
                            <td><a href="#" onclick="downloadPayrollFile('{{ url('/api/download/'.$company_idnumber.'/'.str_replace(['NIS-', 'NAS-'], ['RptaNI-', 'RptaNA-'], $document->xml)) }}'); return false;" title="Descargar RptaDIAN"><i class="fa fa-download"></i></a></td>
                            <td><a href="#" onclick="downloadPayrollFile('{{ url('/api/download/'.$company_idnumber.'/'.str_replace('.xml', '.zip', $document->xml)) }}'); return false;" title="Descargar ZIP"><i class="fa fa-download"></i></a></td>
                            <td><form action="{{ url('/api/send-email-employee/NO') }}" method="POST" onsubmit="return submitPayrollAction(event, this);">
                                    <input type="hidden" name="company_idnumber" value="{{$company_idnumber}}">
                                    <input type="hidden" name="prefix" value="{{$document->prefix}}">
                                    <input type="hidden" name="number" value="{{$document->consecutive}}">
                                    <button type="submit" class="fa fa-envelope"></button>
                                </form>
                            </td>
                        @else
                            <td><form action="{{ route('downloadfile') }}" method="POST" onsubmit="return downloadPayrollPost(event, this);">
                                    <input type="hidden" name="identification" value="{{$company_idnumber}}">
                                    <input type="hidden" name="file" value="{{$document->xml}}">
                                    <input type="hidden" name="type_response" value="false">
                                    <button type="submit" class="fa fa-download"></button>
                                </form>
                            </td>
                            <td><form action="{{ route('downloadfile') }}" method="POST" onsubmit="return downloadPayrollPost(event, this);">
                                    <input type="hidden" name="identification" value="{{$company_idnumber}}">
                                    <input type="hidden" name="file" value="{{$document->pdf}}">
                                    <input type="hidden" name="type_response" value="false">
                                    <button type="submit" class="fa fa-download"></button>
                                </form>
                            </td>
                            <td><form action="{{ url('/api/send-email-employee/NO') }}" method="POST" onsubmit="return submitPayrollAction(event, this);">
                                    <input type="hidden" name="company_idnumber" value="{{$company_idnumber}}">
                                    <input type="hidden" name="prefix" value="{{$document->prefix}}">
                                    <input type="hidden" name="number" value="{{$document->consecutive}}">
                                    <button type="submit" class="fa fa-envelope"></button>
                                </form>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="d-flex justify-content-end px-2 pb-2 pt-1">
        {!! $documents->appends(request()->query())->links('partials.pagination') !!}
    </div>
@endif

<script>
function showPayrollToast(message, type) {
    var toast = document.getElementById('payroll-toast');
    var box = document.getElementById('payroll-toast-box');
    var icon = document.getElementById('payroll-toast-icon');
    box.className = 'alert alert-dismissible fade show mb-0 alert-' + (type === 'success' ? 'success' : type === 'danger' ? 'danger' : 'warning');
    icon.innerHTML = type === 'success'
        ? '<strong><i class="fa fa-check-circle"></i> </strong>'
        : '<strong><i class="fa fa-exclamation-triangle"></i> </strong>';
    document.getElementById('payroll-toast-msg').textContent = message;
    toast.style.display = 'block';
    setTimeout(function() { toast.style.display = 'none'; }, 6000);
}

function triggerBlobDownload(blob, disposition) {
    var filename = 'archivo';
    if (disposition) {
        var match = disposition.match(/filename="?([^"]+)"?/);
        if (match) filename = match[1];
    }
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(a.href);
}

function downloadPayrollFile(url) {
    fetch(url).then(function(response) {
        var ct = response.headers.get('content-type') || '';
        if (ct.indexOf('application/json') !== -1) {
            return response.json().then(function(data) {
                showPayrollToast(data.message || 'El archivo no fue encontrado.', 'warning');
            });
        }
        if (!response.ok) {
            showPayrollToast('El archivo solicitado no fue encontrado.', 'warning');
            return;
        }
        return response.blob().then(function(blob) {
            triggerBlobDownload(blob, response.headers.get('content-disposition'));
        });
    }).catch(function() {
        showPayrollToast('Error al intentar descargar el archivo.', 'danger');
    });
}

function downloadPayrollPost(event, form) {
    event.preventDefault();
    var formData = new FormData(form);
    fetch(form.action, {
        method: 'POST',
        body: formData
    }).then(function(response) {
        var ct = response.headers.get('content-type') || '';
        if (ct.indexOf('application/json') !== -1) {
            return response.json().then(function(data) {
                if (!data.success) {
                    showPayrollToast(data.message || 'El archivo no fue encontrado.', 'warning');
                }
            });
        }
        if (!response.ok) {
            showPayrollToast('El archivo solicitado no fue encontrado.', 'warning');
            return;
        }
        return response.blob().then(function(blob) {
            triggerBlobDownload(blob, response.headers.get('content-disposition'));
        });
    }).catch(function() {
        showPayrollToast('Error al intentar descargar el archivo.', 'danger');
    });
    return false;
}

function submitPayrollAction(event, form) {
    event.preventDefault();
    var btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    var formData = new FormData(form);
    fetch(form.action, {
        method: 'POST',
        body: formData
    }).then(function(response) {
        var ct = response.headers.get('content-type') || '';
        if (ct.indexOf('application/json') !== -1) {
            return response.json().then(function(data) {
                if (data.success) {
                    showPayrollToast(data.message || 'Operación realizada con éxito.', 'success');
                } else {
                    showPayrollToast(data.message || 'No se pudo completar la operación.', 'warning');
                }
            });
        }
        if (!response.ok) {
            showPayrollToast('Error al procesar la solicitud.', 'danger');
            return;
        }
        showPayrollToast('Operación realizada.', 'success');
    }).catch(function() {
        showPayrollToast('Error de conexión al procesar la solicitud.', 'danger');
    }).finally(function() {
        btn.disabled = false;
    });
    return false;
}

document.addEventListener('click', function(e) {
    var el = e.target.closest ? e.target.closest('.payroll-email-error') : null;
    if (el) showPayrollToast(el.getAttribute('data-error'), 'danger');
});
</script>