@extends('layouts.app')

@php
    /** Formatea bytes a una unidad legible (B, KB, MB, GB…). */
    if (!function_exists('backupFmtBytes')) {
        function backupFmtBytes($bytes) {
            if ($bytes === null) return '—';
            $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
            $i = 0; $n = (float) $bytes;
            while ($n >= 1024 && $i < count($units) - 1) { $n /= 1024; $i++; }
            return number_format($n, ($n >= 100 || $i === 0) ? 0 : 1) . ' ' . $units[$i];
        }
    }
@endphp

@section('content')
<header class="page-header">
    <div class="d-flex align-items-center justify-content-between flex-wrap">
        <div class="d-flex align-items-center">
            <h2 class="h4 mb-0 mr-2">Copias de seguridad</h2>
            <span class="badge badge-secondary ml-2">Backups</span>
        </div>
        <div class="d-flex align-items-center">
            <small class="text-muted mr-2">Calculado: {{ $generatedAt }}</small>
            <form action="{{ route('backups.recalculate') }}" method="POST" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Volver a calcular el peso ahora">
                    <i class="fas fa-sync-alt"></i> Recalcular
                </button>
            </form>
        </div>
    </div>
</header>

{{-- ====================== Mensajes ====================== --}}
@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if(session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
@endif

{{-- ====================== Tarjetas resumen ====================== --}}
<div class="row">
    <div class="col-12 col-md-3">
        <section class="card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h4 class="mb-0" style="font-size:1rem;"><i class="fas fa-hdd mr-1 text-warning"></i> Espacio ocupado</h4>
                    <strong class="h4 mb-0">{{ backupFmtBytes($totalBytes) }}</strong>
                </div>
                <small class="text-muted">Peso total de los documentos (storage)</small>
            </div>
        </section>
    </div>

    @php
        $diskUsed = ($diskTotal && $diskFree !== null) ? $diskTotal - $diskFree : null;
        $diskPct  = ($diskTotal && $diskUsed !== null) ? (int) round($diskUsed / $diskTotal * 100) : null;
        $diskBar  = $diskPct === null ? 'bg-secondary' : ($diskPct >= 90 ? 'bg-danger' : ($diskPct >= 80 ? 'bg-warning' : 'bg-success'));
    @endphp
    <div class="col-12 col-md-3">
        <section class="card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h4 class="mb-0" style="font-size:1rem;"><i class="fas fa-server mr-1 {{ $diskPct !== null && $diskPct >= 90 ? 'text-danger' : 'text-info' }}"></i> Disco libre</h4>
                    <strong class="h4 mb-0">{{ $diskFree !== null ? backupFmtBytes($diskFree) : '—' }}</strong>
                </div>
                <div class="progress mb-1" style="height:6px;">
                    <div class="progress-bar {{ $diskBar }}" @style(['width: '.($diskPct ?? 0).'%'])></div>
                </div>
                <small class="text-muted">
                    @if($diskTotal)
                        {{ $diskPct }}% usado de {{ backupFmtBytes($diskTotal) }}
                        @if($diskPct !== null && $diskPct >= 90)<span class="text-danger d-block">⚠ Espacio crítico</span>@endif
                    @else
                        Espacio del disco no disponible
                    @endif
                </small>
            </div>
        </section>
    </div>

    <div class="col-12 col-md-3">
        <section class="card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h4 class="mb-0" style="font-size:1rem;"><i class="fas fa-building mr-1 text-primary"></i> Empresas</h4>
                    <strong class="h4 mb-0">{{ number_format($companyCount) }}</strong>
                </div>
                <small class="text-muted">Empresas que ocupan espacio en disco</small>
            </div>
        </section>
    </div>

    <div class="col-12 col-md-3">
        <section class="card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h4 class="mb-0" style="font-size:1rem;"><i class="fas fa-archive mr-1 text-success"></i> Respaldar todo</h4>
                    <form action="{{ route('backups.store') }}" method="POST" class="d-inline">
                        @csrf
                        <input type="hidden" name="scope" value="all">
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="fas fa-file-archive"></i> Generar ZIP
                        </button>
                    </form>
                </div>
                <small class="text-muted">ZIP con los archivos de todas las empresas</small>
            </div>
        </section>
    </div>
</div>

{{-- ====================== Peso por empresa ====================== --}}
<div class="row">
    <div class="col-12">
        <section class="card mb-4">
            <header class="card-header">
                <h2 class="card-title mb-0" style="font-size:1rem;">
                    <i class="fas fa-building mr-1"></i> Peso en disco por empresa
                </h2>
                <p class="card-subtitle text-muted mb-0" style="font-size:.8rem;">
                    Genera un respaldo descargable (todo o por año/rango) y luego libera espacio borrando esos archivos. Dato precalculado.
                </p>
            </header>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                            <tr>
                                <th style="width:160px;">NIT</th>
                                <th>Empresa</th>
                                <th class="text-right" style="width:130px;">Tamaño</th>
                                <th class="text-center" style="width:260px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($companies as $c)
                                <tr>
                                    <td>{{ $c['nit'] }}</td>
                                    <td>{{ $c['name'] }}</td>
                                    <td class="text-right">{{ backupFmtBytes($c['bytes']) }}</td>
                                    <td class="text-center">
                                        <form action="{{ route('backups.store') }}" method="POST" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="scope" value="company">
                                            <input type="hidden" name="company_id" value="{{ $c['id'] }}">
                                            <button type="submit" class="btn btn-sm btn-outline-primary" title="Generar ZIP completo de esta empresa">
                                                <i class="fas fa-file-archive"></i> Respaldar todo
                                            </button>
                                        </form>
                                        <button type="button" class="btn btn-sm btn-outline-danger btn-manage"
                                            data-company-id="{{ $c['id'] }}"
                                            data-nit="{{ $c['nit'] }}"
                                            data-name="{{ $c['name'] }}"
                                            title="Respaldar/borrar por año o rango de fechas">
                                            <i class="fas fa-calendar-alt"></i> Liberar Espacio
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">
                                        Aún no hay archivos guardados para mostrar.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr class="font-weight-bold">
                                <td colspan="2" class="text-right">Total</td>
                                <td class="text-right">{{ backupFmtBytes($totalBytes) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>

{{-- ====================== Respaldos generados ====================== --}}
<div class="row">
    <div class="col-12">
        <section class="card mb-4">
            <header class="card-header">
                <h2 class="card-title mb-0" style="font-size:1rem;">
                    <i class="fas fa-file-archive mr-1"></i> Respaldos generados
                </h2>
                <p class="card-subtitle text-muted mb-0" style="font-size:.8rem;">
                    Los respaldos se generan en segundo plano. La tabla se actualiza sola; descarga el ZIP cuando esté «Listo».
                </p>
            </header>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th style="width:90px;">Alcance</th>
                                <th>Empresa</th>
                                <th style="width:130px;">Rango</th>
                                <th style="width:140px;">Solicitado</th>
                                <th class="text-center" style="width:110px;">Estado</th>
                                <th class="text-right" style="width:100px;">Tamaño</th>
                                <th class="text-right" style="width:80px;">Archivos</th>
                                <th class="text-center" style="width:160px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="backups-body">
                            <tr><td colspan="8" class="text-center text-muted">Cargando…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>

{{-- ====================== Liberaciones de espacio ====================== --}}
<div class="row">
    <div class="col-12">
        <section class="card mb-4">
            <header class="card-header">
                <h2 class="card-title mb-0" style="font-size:1rem;">
                    <i class="fas fa-trash-alt mr-1 text-danger"></i> Liberaciones de espacio
                </h2>
                <p class="card-subtitle text-muted mb-0" style="font-size:.8rem;">
                    Borrados de archivos reales (en segundo plano). La tabla se actualiza sola.
                </p>
            </header>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th style="width:90px;">Alcance</th>
                                <th>Empresa</th>
                                <th style="width:140px;">Rango</th>
                                <th style="width:140px;">Solicitado</th>
                                <th class="text-center" style="width:110px;">Estado</th>
                                <th class="text-right" style="width:90px;">Borrados</th>
                                <th class="text-right" style="width:110px;">Liberado</th>
                            </tr>
                        </thead>
                        <tbody id="purges-body">
                            <tr><td colspan="7" class="text-center text-muted">Cargando…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>

{{-- ====================== Modal: gestionar por fecha ====================== --}}
<div id="manage-modal" style="display:none;position:fixed;inset:0;z-index:1050;">
    <div id="manage-backdrop" style="position:absolute;inset:0;background:rgba(0,0,0,.5);"></div>
    <div style="position:relative;max-width:620px;margin:6vh auto;background:#fff;border-radius:4px;box-shadow:0 5px 20px rgba(0,0,0,.3);max-height:88vh;display:flex;flex-direction:column;">
        <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
            <h5 class="mb-0"><i class="fas fa-calendar-alt mr-1"></i> Gestionar por fecha — <span id="manage-target"></span></h5>
            <button type="button" id="manage-close" class="close" aria-label="Cerrar" style="background:none;border:none;font-size:1.5rem;">&times;</button>
        </div>
        <div class="p-3" style="overflow-y:auto;">
            {{-- Filtro --}}
            <div class="form-row align-items-end">
                <div class="form-group col-12 col-sm-5 mb-2">
                    <label class="mb-1" style="font-size:.8rem;">Año</label>
                    <select id="flt-year" class="form-control form-control-sm">
                        <option value="">Cargando…</option>
                    </select>
                </div>
                <div class="form-group col-6 col-sm-3 mb-2">
                    <label class="mb-1" style="font-size:.8rem;">Desde</label>
                    <input type="date" id="flt-from" class="form-control form-control-sm">
                </div>
                <div class="form-group col-6 col-sm-3 mb-2">
                    <label class="mb-1" style="font-size:.8rem;">Hasta</label>
                    <input type="date" id="flt-to" class="form-control form-control-sm">
                </div>
                <div class="form-group col-12 col-sm-1 mb-2">
                    <button type="button" id="flt-clear" class="btn btn-sm btn-light btn-block" title="Limpiar filtro">✕</button>
                </div>
            </div>
            <p class="text-muted mb-2" style="font-size:.78rem;">
                El filtro usa la fecha del archivo. Si pones un rango (Desde/Hasta), tiene prioridad sobre el Año.
            </p>

            {{-- Vista previa --}}
            <div class="alert alert-info py-2" id="flt-preview" style="font-size:.85rem;">
                Calculando…
            </div>

            {{-- Acción: respaldar --}}
            <div class="d-flex align-items-center justify-content-between border rounded p-2 mb-3">
                <div style="font-size:.85rem;">
                    <strong><i class="fas fa-file-archive mr-1 text-primary"></i> Respaldar este rango</strong><br>
                    <span class="text-muted">Genera un ZIP descargable con lo que coincide arriba.</span>
                </div>
                <button type="button" id="btn-backup-range" class="btn btn-sm btn-primary">
                    <i class="fas fa-download"></i> Generar ZIP
                </button>
            </div>

            {{-- Zona de peligro: borrar --}}
            <div class="border border-danger rounded p-2">
                <div class="text-danger mb-2" style="font-size:.9rem;">
                    <strong><i class="fas fa-exclamation-triangle mr-1"></i> Liberar espacio (borrar definitivamente)</strong>
                </div>
                <div class="alert alert-danger py-2 mb-2" style="font-size:.82rem;">
                    Se borrarán <strong>permanentemente</strong> los archivos que coinciden con el filtro de arriba.
                    Esto <u>no se puede deshacer</u>. Genera y <strong>descarga</strong> el respaldo antes.
                </div>
                <div class="form-group mb-2">
                    <label class="mb-1" style="font-size:.82rem;">1) Escribe <code id="flt-expected"></code> para confirmar:</label>
                    <input type="text" id="flt-confirm" class="form-control form-control-sm" autocomplete="off">
                </div>
                <div class="form-check mb-2">
                    <input type="checkbox" id="flt-ack" class="form-check-input">
                    <label class="form-check-label" for="flt-ack" style="font-size:.82rem;">
                        2) Entiendo que esto borrará los archivos de forma permanente.
                    </label>
                </div>
                <button type="button" id="btn-delete" class="btn btn-sm btn-danger" disabled>
                    <i class="fas fa-trash-alt"></i> Borrar definitivamente
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    var listUrl = "{{ route('backups.list') }}";
    var purgesUrl = "{{ route('backups.purges') }}";
    var storeUrl = "{{ route('backups.store') }}";
    var freeUrl = "{{ route('backups.free') }}";
    var previewBase = "{{ url('backups/company') }}"; // + /{id}/preview
    var downloadBase = "{{ url('backups') }}";
    var csrf = "{{ csrf_token() }}";

    function fmtBytes(bytes) {
        if (bytes === null || bytes === undefined) return '—';
        var units = ['B','KB','MB','GB','TB','PB']; var i = 0, n = bytes;
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        return n.toFixed(n >= 100 || i === 0 ? 0 : 1) + ' ' + units[i];
    }
    function esc(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }
    function submitForm(action, fields) {
        var f = document.createElement('form');
        f.method = 'POST'; f.action = action;
        fields._token = csrf;
        Object.keys(fields).forEach(function (k) {
            var v = fields[k];
            if (v === null || v === undefined || v === '') return;
            var i = document.createElement('input');
            i.type = 'hidden'; i.name = k; i.value = v; f.appendChild(i);
        });
        document.body.appendChild(f); f.submit();
    }

    /* ----------------------- Lista de respaldos ----------------------- */
    function statusBadge(s) {
        if (s === 'done')       return '<span class="badge badge-success">Listo</span>';
        if (s === 'processing') return '<span class="badge badge-info">Generando…</span>';
        if (s === 'failed')     return '<span class="badge badge-danger">Falló</span>';
        return '<span class="badge badge-secondary">En cola</span>';
    }
    function renderBackups(list) {
        var body = document.getElementById('backups-body');
        if (!list.length) {
            body.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">Aún no has generado ningún respaldo.</td></tr>';
            return;
        }
        body.innerHTML = list.map(function (b) {
            var scope = b.scope === 'all'
                ? '<span class="badge badge-dark">Todas</span>'
                : '<span class="badge badge-light">Empresa</span>';
            var target = b.scope === 'all'
                ? 'Todas las empresas'
                : (esc(b.nit || '') + (b.name ? ' · ' + esc(b.name) : ''));
            var dl = b.downloaded_at ? ' <i class="fas fa-check text-success" title="Descargado: ' + esc(b.downloaded_at) + '"></i>' : '';
            // Motivo del fallo, visible (no solo en tooltip).
            if (b.status === 'failed' && b.error) {
                target += '<br><small class="text-danger"><i class="fas fa-exclamation-triangle"></i> ' + esc(b.error) + '</small>';
            }

            var actions = '';
            if (b.can_download) {
                actions += '<a href="' + downloadBase + '/' + b.id + '/download" class="btn btn-sm btn-outline-success">' +
                           '<i class="fas fa-download"></i> Descargar</a> ';
            }
            actions += '<form action="' + downloadBase + '/' + b.id + '" method="POST" class="d-inline" ' +
                       'onsubmit="return confirm(\'¿Eliminar este respaldo (solo el ZIP)?\');">' +
                       '<input type="hidden" name="_token" value="' + csrf + '">' +
                       '<input type="hidden" name="_method" value="DELETE">' +
                       '<button type="submit" class="btn btn-sm btn-outline-secondary" title="Eliminar el ZIP"><i class="fas fa-trash"></i></button>' +
                       '</form>';

            var sizeOrError = b.status === 'failed'
                ? '<span class="text-danger" title="' + esc(b.error || '') + '">error</span>'
                : fmtBytes(b.size_bytes);

            return '<tr>' +
                '<td>' + scope + '</td>' +
                '<td>' + target + dl + '</td>' +
                '<td><small>' + esc(b.label || 'Todo') + '</small></td>' +
                '<td>' + esc(b.created_at || '') + '</td>' +
                '<td class="text-center">' + statusBadge(b.status) + '</td>' +
                '<td class="text-right">' + sizeOrError + '</td>' +
                '<td class="text-right">' + (b.files_count !== null && b.files_count !== undefined ? b.files_count : '—') + '</td>' +
                '<td class="text-center">' + actions + '</td>' +
                '</tr>';
        }).join('');
    }
    /* ----------------------- Lista de liberaciones ----------------------- */
    function renderPurges(list) {
        var body = document.getElementById('purges-body');
        if (!list.length) {
            body.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">Aún no has liberado espacio.</td></tr>';
            return;
        }
        body.innerHTML = list.map(function (p) {
            var scope = p.scope === 'all'
                ? '<span class="badge badge-dark">Todas</span>'
                : '<span class="badge badge-light">Empresa</span>';
            var target = p.scope === 'all'
                ? 'Todas las empresas'
                : (esc(p.nit || '') + (p.name ? ' · ' + esc(p.name) : ''));
            if (p.status === 'failed' && p.error) {
                target += '<br><small class="text-danger"><i class="fas fa-exclamation-triangle"></i> ' + esc(p.error) + '</small>';
            }
            var st = p.status === 'done'       ? '<span class="badge badge-success">Hecho</span>'
                   : p.status === 'processing' ? '<span class="badge badge-info">Borrando…</span>'
                   : p.status === 'failed'     ? '<span class="badge badge-danger">Falló</span>'
                   :                             '<span class="badge badge-secondary">En cola</span>';
            return '<tr>' +
                '<td>' + scope + '</td>' +
                '<td>' + target + '</td>' +
                '<td><small>' + esc(p.label || 'Todo') + '</small></td>' +
                '<td>' + esc(p.created_at || '') + '</td>' +
                '<td class="text-center">' + st + '</td>' +
                '<td class="text-right">' + (p.deleted_files !== null && p.deleted_files !== undefined ? p.deleted_files : '—') + '</td>' +
                '<td class="text-right">' + (p.freed_bytes !== null && p.freed_bytes !== undefined ? fmtBytes(p.freed_bytes) : '—') + '</td>' +
                '</tr>';
        }).join('');
    }

    // Polling inteligente: re-consulta respaldos Y liberaciones mientras haya algo
    // en cola/procesándose; se pausa si la pestaña no está visible; y para cuando
    // todo terminó. Una sola tanda consulta ambas tablas.
    var pollTimer = null;
    function hasActive(list) {
        return list.some(function (x) { return x.status === 'pending' || x.status === 'processing'; });
    }
    function scheduleNext(active) {
        clearTimeout(pollTimer); pollTimer = null;
        if (active && !document.hidden) {
            pollTimer = setTimeout(loadAll, 5000);
        }
    }
    function loadAll() {
        Promise.all([
            fetch(listUrl, {headers: {'X-Requested-With': 'XMLHttpRequest'}}).then(function (r) { return r.json(); }),
            fetch(purgesUrl, {headers: {'X-Requested-With': 'XMLHttpRequest'}}).then(function (r) { return r.json(); })
        ]).then(function (res) {
            var backups = (res[0] && res[0].backups) || [];
            var purges  = (res[1] && res[1].purges) || [];
            renderBackups(backups);
            renderPurges(purges);
            scheduleNext(hasActive(backups) || hasActive(purges));
        }).catch(function () { scheduleNext(true); }); // si falla, reintenta
    }

    /* ----------------------- Modal gestionar por fecha ----------------------- */
    var current = { id: null, nit: null, name: null };
    var previewData = { total: {files:0, bytes:0}, years: [] };
    var matchCount = 0;
    var rangeTimer = null;

    function openManage(data) {
        current = data;
        document.getElementById('manage-target').textContent = (data.nit || '') + (data.name ? ' · ' + data.name : '');
        document.getElementById('flt-expected').textContent = data.nit;
        document.getElementById('flt-year').innerHTML = '<option value="">Cargando…</option>';
        document.getElementById('flt-from').value = '';
        document.getElementById('flt-to').value = '';
        document.getElementById('flt-confirm').value = '';
        document.getElementById('flt-ack').checked = false;
        document.getElementById('flt-preview').textContent = 'Calculando…';
        document.getElementById('btn-delete').disabled = true;
        document.getElementById('manage-modal').style.display = 'block';
        document.body.style.overflow = 'hidden';
        loadPreview();
    }
    function closeManage() {
        document.getElementById('manage-modal').style.display = 'none';
        document.body.style.overflow = '';
    }

    function loadPreview() {
        // Primer escaneo (sin rango): desglose por año + total.
        fetch(previewBase + '/' + current.id + '/preview', {headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (d) {
                previewData = d;
                var sel = document.getElementById('flt-year');
                var opts = '<option value="">Todos (' + d.total.files + ' archivos · ' + fmtBytes(d.total.bytes) + ')</option>';
                (d.years || []).forEach(function (y) {
                    opts += '<option value="' + y.year + '">' + y.year + ' — ' + y.files + ' archivos · ' + fmtBytes(y.bytes) + '</option>';
                });
                sel.innerHTML = opts;
                updatePreview();
            })
            .catch(function () {
                document.getElementById('flt-year').innerHTML = '<option value="">Error al cargar</option>';
                document.getElementById('flt-preview').className = 'alert alert-danger py-2';
                document.getElementById('flt-preview').textContent = 'No se pudo calcular el peso de esta empresa.';
            });
    }

    function setMatch(files, bytes) {
        matchCount = files;
        var el = document.getElementById('flt-preview');
        el.className = 'alert alert-info py-2';
        el.innerHTML = 'Coinciden <strong>' + files + '</strong> archivos · <strong>' + fmtBytes(bytes) + '</strong>';
        validateDelete();
    }

    function updatePreview() {
        var from = document.getElementById('flt-from').value;
        var to = document.getElementById('flt-to').value;
        var year = document.getElementById('flt-year').value;

        if (from || to) {
            // Rango personalizado: requiere un escaneo con ese rango.
            document.getElementById('flt-preview').textContent = 'Calculando rango…';
            var qs = [];
            if (from) qs.push('from=' + encodeURIComponent(from));
            if (to) qs.push('to=' + encodeURIComponent(to));
            fetch(previewBase + '/' + current.id + '/preview?' + qs.join('&'), {headers: {'X-Requested-With': 'XMLHttpRequest'}})
                .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                .then(function (d) {
                    var rg = d.range || {files:0, bytes:0};
                    setMatch(rg.files, rg.bytes);
                })
                .catch(function () {
                    matchCount = 0;
                    document.getElementById('btn-delete').disabled = true;
                    document.getElementById('flt-preview').className = 'alert alert-danger py-2';
                    document.getElementById('flt-preview').textContent = 'No se pudo calcular el rango.';
                });
            return;
        }

        if (year) {
            var y = (previewData.years || []).find(function (it) { return String(it.year) === String(year); });
            if (y) { setMatch(y.files, y.bytes); return; }
        }
        setMatch(previewData.total.files, previewData.total.bytes);
    }

    function validateDelete() {
        var txt = document.getElementById('flt-confirm').value.trim();
        var ok = (txt === current.nit) && document.getElementById('flt-ack').checked && matchCount > 0;
        document.getElementById('btn-delete').disabled = !ok;
    }

    function currentFilterFields() {
        return {
            scope: 'company',
            company_id: current.id,
            year: document.getElementById('flt-year').value,
            from: document.getElementById('flt-from').value,
            to: document.getElementById('flt-to').value
        };
    }

    document.addEventListener('DOMContentLoaded', function () {
        loadAll(); // una vez al cargar; se re-programa solo si hay algo en proceso

        // Al volver a la pestaña, refresca de inmediato (y reanuda si hace falta).
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) loadAll();
        });

        Array.prototype.forEach.call(document.querySelectorAll('.btn-manage'), function (btn) {
            btn.addEventListener('click', function () {
                openManage({
                    id: btn.getAttribute('data-company-id'),
                    nit: btn.getAttribute('data-nit'),
                    name: btn.getAttribute('data-name')
                });
            });
        });

        document.getElementById('manage-close').addEventListener('click', closeManage);
        document.getElementById('manage-backdrop').addEventListener('click', closeManage);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeManage(); });

        // Cambios de filtro.
        document.getElementById('flt-year').addEventListener('change', function () {
            // Elegir año descarta el rango.
            document.getElementById('flt-from').value = '';
            document.getElementById('flt-to').value = '';
            updatePreview();
        });
        function onRangeChange() {
            // Usar rango descarta el año seleccionado.
            document.getElementById('flt-year').value = '';
            clearTimeout(rangeTimer);
            rangeTimer = setTimeout(updatePreview, 300);
        }
        document.getElementById('flt-from').addEventListener('change', onRangeChange);
        document.getElementById('flt-to').addEventListener('change', onRangeChange);
        document.getElementById('flt-clear').addEventListener('click', function () {
            document.getElementById('flt-year').value = '';
            document.getElementById('flt-from').value = '';
            document.getElementById('flt-to').value = '';
            updatePreview();
        });

        document.getElementById('flt-confirm').addEventListener('input', validateDelete);
        document.getElementById('flt-ack').addEventListener('change', validateDelete);

        // Respaldar el rango actual.
        document.getElementById('btn-backup-range').addEventListener('click', function () {
            submitForm(storeUrl, currentFilterFields());
        });

        // Borrar definitivamente el rango actual.
        document.getElementById('btn-delete').addEventListener('click', function () {
            if (document.getElementById('btn-delete').disabled) return;
            var fields = currentFilterFields();
            fields.confirm_text = document.getElementById('flt-confirm').value;
            fields.confirm_ack = '1';
            submitForm(freeUrl, fields);
        });
    });
})();
</script>
@endpush
