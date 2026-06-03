@extends('layouts.app')
@section('content')
<header class="page-header">
    <div class="d-flex align-items-center justify-content-between flex-wrap">
        <div class="d-flex align-items-center">
            <h2 class="h4 mb-0 me-2">Monitoreo del servidor</h2>
            <span id="env-badge" class="badge badge-secondary ml-2">{{ $current['environment'] ?? '—' }}</span>
            <span id="host-badge" class="text-muted ml-2" style="font-size:.8rem;">{{ $current['hostname'] ?? '' }}</span>
        </div>
        <div class="d-flex align-items-center">
            <small id="last-update" class="text-muted"></small>
        </div>
    </div>
</header>

{{-- ====================== Tarjetas de recursos en vivo ====================== --}}
<div class="row">
    <div class="col-12 col-md-4">
        <section class="card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h4 class="mb-0" style="font-size:1rem;"><i class="fas fa-microchip mr-1 text-primary"></i> CPU</h4>
                    <strong id="cpu-val" class="h4 mb-0">—</strong>
                </div>
                <div class="progress" style="height:8px;">
                    <div id="cpu-bar" class="progress-bar bg-primary" role="progressbar" style="width:0%"></div>
                </div>
                <small class="text-muted">Carga: <span id="load-val">—</span> · Núcleos: <span id="cores-val">—</span></small>
            </div>
        </section>
    </div>

    <div class="col-12 col-md-4">
        <section class="card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h4 class="mb-0" style="font-size:1rem;"><i class="fas fa-memory mr-1 text-success"></i> Memoria</h4>
                    <strong id="mem-val" class="h4 mb-0">—</strong>
                </div>
                <div class="progress" style="height:8px;">
                    <div id="mem-bar" class="progress-bar bg-success" role="progressbar" style="width:0%"></div>
                </div>
                <small class="text-muted"><span id="mem-used">—</span> / <span id="mem-total">—</span></small>
            </div>
        </section>
    </div>

    <div class="col-12 col-md-4">
        <section class="card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h4 class="mb-0" style="font-size:1rem;"><i class="fas fa-hdd mr-1 text-warning"></i> Almacenamiento</h4>
                    <strong id="disk-val" class="h4 mb-0">—</strong>
                </div>
                <div class="progress" style="height:8px;">
                    <div id="disk-bar" class="progress-bar bg-warning" role="progressbar" style="width:0%"></div>
                </div>
                <small class="text-muted"><span id="disk-used">—</span> / <span id="disk-total">—</span></small>
            </div>
        </section>
    </div>
</div>

{{-- ====================== Gráfico histórico ====================== --}}
<div class="row">
    <div class="col-12">
        <section class="card mb-4">
            <header class="card-header d-flex justify-content-between align-items-center">
                <h2 class="card-title mb-0" style="font-size:1rem;">Consumo histórico</h2>
                <select id="range-select" class="form-control form-control-sm" style="width:auto;">
                    <option value="1">Última hora</option>
                    <option value="6" selected>Últimas 6 horas</option>
                    <option value="24">Últimas 24 horas</option>
                    <option value="168">Últimos 7 días</option>
                    <option value="720">Últimos 30 días</option>
                </select>
            </header>
            <div class="card-body">
                <div id="history-empty" class="alert alert-info" style="display:none;">
                    Aún no hay registros históricos. Se generan automáticamente cada minuto
                    (requiere el contenedor de scheduling activo), o puedes forzar uno con
                    <code>php artisan monitor:snapshot</code>.
                </div>
                <div style="position:relative;height:320px;">
                    <canvas id="history-chart"></canvas>
                </div>
            </div>
        </section>
    </div>
</div>

{{-- ====================== Consumo por empresa ====================== --}}
<div class="row">
    <div class="col-12">
        <section class="card mb-4">
            <header class="card-header d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h2 class="card-title mb-0" style="font-size:1rem;">
                        <i class="fas fa-building mr-1"></i> Consumo por empresa
                    </h2>
                    <p class="card-subtitle text-muted mb-0" style="font-size:.8rem;">
                        Espacio en disco (storage) que ocupan los archivos de cada empresa.
                        <span id="company-generated"></span>
                    </p>
                </div>
                <div class="d-flex align-items-center">
                    <input type="text" id="company-search" class="form-control form-control-sm" placeholder="Buscar NIT o empresa…" style="width:200px;">
                </div>
            </header>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                            <tr>
                                <th style="width:160px;">NIT</th>
                                <th>Empresa</th>
                                <th class="text-right" style="width:160px;">Storage</th>
                            </tr>
                        </thead>
                        <tbody id="companies-body">
                            <tr><td colspan="3" class="text-center text-muted">Cargando…</td></tr>
                        </tbody>
                        <tfoot>
                            <tr class="font-weight-bold">
                                <td colspan="2" class="text-right">Total</td>
                                <td class="text-right" id="storage-total">—</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>

{{-- ====================== Momentos de mayor uso ====================== --}}
<div class="row">
    <div class="col-12">
        <section class="card mb-4">
            <header class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="card-title mb-0" style="font-size:1rem;">
                        <i class="fas fa-chart-line mr-1 text-danger"></i> Momentos de mayor uso de CPU
                    </h2>
                    <p class="card-subtitle text-muted mb-0" style="font-size:.8rem;">
                        Cuando el CPU se satura (≥{{ (int) config('monitoring.cpu_saturation', 85) }}%) se capturan las consultas y procesos que estaban corriendo.
                        Usa el botón <strong>Detalles</strong> para ver qué saturó el sistema.
                    </p>
                </div>
                <select id="peaks-range" class="form-control form-control-sm" style="width:auto;">
                    <option value="24" selected>Últimas 24 h</option>
                    <option value="168">Últimos 7 días</option>
                    <option value="720">Últimos 30 días</option>
                </select>
            </header>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Fecha/hora</th>
                                <th class="text-right" style="width:100px;">CPU%</th>
                                <th class="text-right" style="width:100px;">RAM%</th>
                                <th class="text-right" style="width:100px;">Disco%</th>
                                <th class="text-center" style="width:130px;">Detalles</th>
                            </tr>
                        </thead>
                        <tbody id="peaks-body">
                            <tr><td colspan="5" class="text-center text-muted">Cargando…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>

{{-- Modal de detalle de un pico (JS puro, sin depender de bootstrap.js) --}}
<div id="peak-modal" style="display:none;position:fixed;inset:0;z-index:1050;">
    <div id="peak-backdrop" style="position:absolute;inset:0;background:rgba(0,0,0,.5);"></div>
    <div style="position:relative;max-width:900px;margin:5vh auto;background:#fff;border-radius:4px;box-shadow:0 5px 20px rgba(0,0,0,.3);max-height:90vh;display:flex;flex-direction:column;">
        <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
            <h5 class="mb-0">Qué saturó el CPU — <span id="peak-modal-time"></span></h5>
            <button type="button" id="peak-modal-close" class="close" aria-label="Cerrar" style="background:none;border:none;font-size:1.5rem;">&times;</button>
        </div>
        <div class="p-3" id="peak-modal-body" style="overflow-y:auto;"></div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    'use strict';

    var routes = {
        live:      "{{ route('monitoring.live') }}",
        history:   "{{ route('monitoring.history') }}",
        peaks:     "{{ route('monitoring.peaks') }}",
        companies: "{{ route('monitoring.companies') }}"
    };

    function fmtBytes(bytes) {
        if (bytes === null || bytes === undefined) return '—';
        var units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        var i = 0, n = bytes;
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        return n.toFixed(n >= 100 || i === 0 ? 0 : 1) + ' ' + units[i];
    }
    function pct(v) { return (v === null || v === undefined) ? '—' : v + '%'; }
    function barClass(v) {
        if (v === null) return '';
        if (v >= 90) return 'bg-danger';
        if (v >= 75) return 'bg-warning';
        return '';
    }
    function esc(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    /* ----------------------- Métricas en vivo ----------------------- */
    function setBar(barId, valId, value) {
        var bar = document.getElementById(barId);
        var val = document.getElementById(valId);
        val.textContent = pct(value);
        var w = (value === null || value === undefined) ? 0 : Math.min(100, value);
        bar.style.width = w + '%';
        var base = barId === 'cpu-bar' ? 'bg-primary' : barId === 'mem-bar' ? 'bg-success' : 'bg-warning';
        bar.className = 'progress-bar ' + (barClass(value) || base);
    }

    function loadLive() {
        fetch(routes.live, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function (r) { return r.json(); })
            .then(function (d) {
                setBar('cpu-bar', 'cpu-val', d.cpu_percent);
                setBar('mem-bar', 'mem-val', d.mem_percent);
                setBar('disk-bar', 'disk-val', d.disk_percent);
                document.getElementById('load-val').textContent =
                    (d.load_1 !== null ? d.load_1 : '—') + ' / ' + (d.load_5 !== null ? d.load_5 : '—') + ' / ' + (d.load_15 !== null ? d.load_15 : '—');
                document.getElementById('cores-val').textContent = d.cpu_cores !== null ? d.cpu_cores : '—';
                document.getElementById('mem-used').textContent = fmtBytes(d.mem_used);
                document.getElementById('mem-total').textContent = fmtBytes(d.mem_total);
                document.getElementById('disk-used').textContent = fmtBytes(d.disk_used);
                document.getElementById('disk-total').textContent = fmtBytes(d.disk_total);
                document.getElementById('env-badge').textContent = d.environment || '—';
                document.getElementById('host-badge').textContent = d.hostname || '';
                document.getElementById('last-update').textContent = 'Actualizado: ' + (d.server_time || '');
            })
            .catch(function () {
                document.getElementById('last-update').textContent = 'Error al actualizar';
            });
    }

    /* ----------------------- Gráfico histórico ----------------------- */
    var chart = null;
    function initChart() {
        var ctx = document.getElementById('history-chart').getContext('2d');
        chart = new Chart(ctx, {
            type: 'line',
            data: { labels: [], datasets: [
                { label: 'CPU %',   data: [], borderColor: '#0088CC', backgroundColor: 'rgba(0,136,204,.08)', borderWidth: 2, tension: .3, pointRadius: 0, fill: true },
                { label: 'RAM %',   data: [], borderColor: '#2BAB67', backgroundColor: 'rgba(43,171,103,.05)', borderWidth: 2, tension: .3, pointRadius: 0, fill: true },
                { label: 'Disco %', data: [], borderColor: '#E8A100', borderWidth: 2, tension: .3, pointRadius: 0, fill: false }
            ]},
            options: {
                responsive: true, maintainAspectRatio: false, animation: false,
                interaction: { mode: 'index', intersect: false },
                scales: { y: { beginAtZero: true, max: 100, title: { display: true, text: '%' } } },
                plugins: { legend: { position: 'top' } }
            }
        });
    }

    function loadHistory() {
        var hours = document.getElementById('range-select').value;
        fetch(routes.history + '?hours=' + hours, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function (r) { return r.json(); })
            .then(function (d) {
                document.getElementById('history-empty').style.display = (d.points && d.points.length) ? 'none' : 'block';
                chart.data.labels = d.points.map(function (p) { return new Date(p.t).toLocaleString(); });
                chart.data.datasets[0].data = d.points.map(function (p) { return p.cpu; });
                chart.data.datasets[1].data = d.points.map(function (p) { return p.mem; });
                chart.data.datasets[2].data = d.points.map(function (p) { return p.disk; });
                chart.update();
            });
    }

    /* ----------------------- Consumo por empresa ----------------------- */
    var companiesCache = [];
    function renderCompanies() {
        var q = (document.getElementById('company-search').value || '').toLowerCase();
        var body = document.getElementById('companies-body');
        var list = companiesCache.filter(function (c) {
            return !q || String(c.nit).toLowerCase().indexOf(q) !== -1 || String(c.name).toLowerCase().indexOf(q) !== -1;
        });
        if (!list.length) {
            body.innerHTML = '<tr><td colspan="3" class="text-center text-muted">Sin resultados</td></tr>';
            return;
        }
        body.innerHTML = list.map(function (c) {
            return '<tr>' +
                '<td>' + esc(c.nit) + '</td>' +
                '<td>' + esc(c.name) + '</td>' +
                '<td class="text-right">' + fmtBytes(c.storage_bytes) + '</td>' +
                '</tr>';
        }).join('');
    }

    function loadCompanies() {
        var body = document.getElementById('companies-body');
        body.innerHTML = '<tr><td colspan="3" class="text-center text-muted">Cargando…</td></tr>';
        fetch(routes.companies, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function (r) { return r.json(); })
            .then(function (d) {
                companiesCache = d.companies || [];
                document.getElementById('storage-total').textContent = fmtBytes(d.storage_total);
                document.getElementById('company-generated').textContent = d.generated_at ? '· Calculado: ' + d.generated_at : '';
                renderCompanies();
            })
            .catch(function () {
                body.innerHTML = '<tr><td colspan="3" class="text-center text-danger">Error al calcular el consumo</td></tr>';
            });
    }

    /* ----------------------- Momentos de mayor uso ----------------------- */
    var peaksCache = [];
    function loadPeaks() {
        var hours = document.getElementById('peaks-range').value;
        fetch(routes.peaks + '?hours=' + hours, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var body = document.getElementById('peaks-body');
                peaksCache = d.peaks || [];
                if (!peaksCache.length) {
                    body.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Sin datos en el rango seleccionado</td></tr>';
                    return;
                }
                body.innerHTML = peaksCache.map(function (p, i) {
                    var cell = p.captured
                        ? '<button type="button" class="btn btn-sm btn-outline-primary" data-idx="' + i + '"><i class="fas fa-search-plus"></i> Detalles</button>'
                        : '<span class="text-muted">—</span>';
                    return '<tr data-idx="' + i + '">' +
                        '<td>' + esc(p.at) + '</td>' +
                        '<td class="text-right"><span class="badge ' + (p.cpu >= 90 ? 'badge-danger' : p.cpu >= 75 ? 'badge-warning' : 'badge-secondary') + '">' + pct(p.cpu) + '</span></td>' +
                        '<td class="text-right">' + pct(p.mem) + '</td>' +
                        '<td class="text-right">' + pct(p.disk) + '</td>' +
                        '<td class="text-center">' + cell + '</td>' +
                        '</tr>';
                }).join('');
            });
    }

    function summaryBar(label, value, used, total, base) {
        var w = (value === null || value === undefined) ? 0 : Math.min(100, value);
        var detail = (used !== null && used !== undefined && total)
            ? fmtBytes(used) + ' / ' + fmtBytes(total)
            : pct(value);
        return '<div class="mb-2">' +
            '<div class="d-flex justify-content-between" style="font-size:.75rem;">' +
                '<span>' + label + '</span><span class="text-muted">' + detail + '</span>' +
            '</div>' +
            '<div class="progress" style="height:6px;">' +
                '<div class="progress-bar ' + (barClass(value) || base) + '" style="width:' + w + '%"></div>' +
            '</div></div>';
    }

    function renderSystemSummary(p) {
        var load = (p.load_1 !== null && p.load_1 !== undefined)
            ? p.load_1 + ' / ' + (p.load_5 !== null ? p.load_5 : '—') + ' / ' + (p.load_15 !== null ? p.load_15 : '—')
            : '—';
        var html = '<div class="card bg-light mb-3"><div class="card-body py-2">';
        html += '<div class="d-flex justify-content-between align-items-center mb-2" style="font-size:.8rem;">' +
                '<strong><i class="fas fa-server mr-1"></i> Estado del sistema al saturarse</strong>' +
                '<span class="text-muted">Carga: ' + esc(load) +
                (p.cpu_cores ? ' · ' + p.cpu_cores + ' núcleos' : '') + '</span>' +
                '</div>';
        html += '<div class="row"><div class="col-md-4">' +
                summaryBar('CPU', p.cpu, null, null, 'bg-primary') + '</div>';
        html += '<div class="col-md-4">' +
                summaryBar('Memoria', p.mem, p.mem_used, p.mem_total, 'bg-success') + '</div>';
        html += '<div class="col-md-4">' +
                summaryBar('Disco', p.disk, p.disk_used, p.disk_total, 'bg-warning') + '</div>';
        html += '</div></div></div>';
        return html;
    }

    function showPeak(idx) {
        var p = peaksCache[idx];
        if (!p || !p.captured) return;
        var queries = p.top_queries || [], processes = p.top_processes || [], slow = p.slow_requests || [];

        document.getElementById('peak-modal-time').textContent = p.at + ' · CPU ' + pct(p.cpu);
        var html = '';
        html += renderSystemSummary(p);

        // --- Consultas SQL ---
        html += '<h6 class="mb-2"><i class="fas fa-database mr-1"></i> Consultas SQL que estaban corriendo</h6>';
        if (queries.length) {
            html += '<div class="table-responsive"><table class="table table-sm table-striped"><thead><tr>' +
                    '<th style="width:70px;">Tiempo</th><th style="width:90px;">Estado</th><th>Consulta</th></tr></thead><tbody>';
            html += queries.map(function (q) {
                return '<tr>' +
                    '<td>' + (q.time !== null && q.time !== undefined ? q.time + 's' : '—') + '</td>' +
                    '<td><small>' + esc(q.state || '') + '</small></td>' +
                    '<td><code style="font-size:.72rem;white-space:normal;word-break:break-word;">' + esc(q.query) + '</code></td>' +
                    '</tr>';
            }).join('');
            html += '</tbody></table></div>';
        } else {
            html += '<p class="text-muted">No se registraron consultas SQL activas en ese momento. Si esto se repite en cada pico, verifica que el usuario de la base de datos tenga el privilegio <code>PROCESS</code>: sin él solo se ven las conexiones propias y no las del resto del sistema.</p>';
        }

        html += '<h6 class="mb-2 mt-3"><i class="fas fa-list-ol mr-1"></i> Procesos que más consumían <small class="text-muted">(vista tipo htop)</small></h6>';
        if (processes.length) {
            html += '<div class="table-responsive"><table class="table table-sm table-striped" style="font-size:.78rem;"><thead><tr>' +
                    '<th style="width:70px;">PID</th>' +
                    '<th style="width:90px;">Usuario</th>' +
                    '<th class="text-right" style="width:64px;">CPU%</th>' +
                    '<th class="text-right" style="width:64px;">MEM%</th>' +
                    '<th class="text-right" style="width:80px;">RES</th>' +
                    '<th style="width:80px;">Tiempo</th>' +
                    '<th>Comando</th></tr></thead><tbody>';
            html += processes.map(function (pr) {
                var cpu = (pr.cpu !== null && pr.cpu !== undefined) ? pr.cpu.toFixed(1) : '—';
                var mem = (pr.mem !== null && pr.mem !== undefined) ? pr.mem.toFixed(1) : '—';
                var cpuCls = 'text-right';
                if (pr.cpu >= 50) cpuCls += ' text-danger font-weight-bold';
                else if (pr.cpu >= 20) cpuCls += ' text-warning';
                return '<tr>' +
                    '<td>' + esc(pr.pid) + '</td>' +
                    '<td>' + esc(pr.user || '—') + '</td>' +
                    '<td class="' + cpuCls + '">' + cpu + '</td>' +
                    '<td class="text-right">' + mem + '</td>' +
                    '<td class="text-right">' + (pr.rss ? fmtBytes(pr.rss) : '—') + '</td>' +
                    '<td>' + esc(pr.etime || '—') + '</td>' +
                    '<td><code style="font-size:.7rem;white-space:normal;word-break:break-all;">' + esc(pr.command || pr.name) + '</code></td>' +
                    '</tr>';
            }).join('');
            html += '</tbody></table></div>';
        } else {
            html += '<p class="text-muted">No se capturaron procesos en ese momento. Si corre en Docker y quieres ver los procesos del VPS (no solo los del contenedor), añade <code>pid: "host"</code> al servicio en docker-compose.</p>';
        }

        // --- Peticiones lentas alrededor de este pico ---
        html += '<h6 class="mb-2 mt-3"><i class="fas fa-hourglass-half mr-1"></i> Peticiones lentas en ese momento</h6>';
        if (slow.length) {
            html += '<div class="table-responsive"><table class="table table-sm table-striped"><thead><tr>' +
                    '<th>Ruta / acción</th><th class="text-right" style="width:80px;">Duración</th>' +
                    '<th class="text-right" style="width:80px;">Consultas</th><th>Consulta más lenta</th></tr></thead><tbody>';
            html += slow.map(function (r) {
                var secs = (r.duration_ms / 1000).toFixed(2) + 's';
                var q = r.slowest_query
                    ? '<code style="font-size:.7rem;white-space:normal;word-break:break-word;">' + esc(r.slowest_query) + '</code>' +
                      (r.slowest_query_ms ? ' <span class="text-muted">(' + (r.slowest_query_ms/1000).toFixed(2) + 's)</span>' : '')
                    : '<span class="text-muted">—</span>';
                return '<tr>' +
                    '<td><span class="badge badge-light">' + esc(r.method) + '</span> ' + esc(r.route || r.url) + '</td>' +
                    '<td class="text-right">' + secs + '</td>' +
                    '<td class="text-right">' + (r.query_count !== null && r.query_count !== undefined ? r.query_count : '—') + '</td>' +
                    '<td>' + q + '</td>' +
                    '</tr>';
            }).join('');
            html += '</tbody></table></div>';
        } else {
            html += '<p class="text-muted">No se registraron peticiones lentas alrededor de este pico.</p>';
        }

        document.getElementById('peak-modal-body').innerHTML = html;
        document.getElementById('peak-modal').style.display = 'block';
    }

    function hidePeak() { document.getElementById('peak-modal').style.display = 'none'; }

    /* ----------------------- Auto-refresh ----------------------- */
    var timer = null;
    function startTimer() { stopTimer(); timer = setInterval(loadLive, 10000); }
    function stopTimer() { if (timer) { clearInterval(timer); timer = null; } }

    document.addEventListener('DOMContentLoaded', function () {
        initChart();
        loadLive();
        loadHistory();
        loadCompanies();
        loadPeaks();
        startTimer();

        document.getElementById('range-select').addEventListener('change', loadHistory);
        document.getElementById('peaks-range').addEventListener('change', loadPeaks);
        document.getElementById('company-search').addEventListener('input', renderCompanies);
        document.getElementById('peaks-body').addEventListener('click', function (e) {
            var tr = e.target.closest('tr[data-idx]');
            if (tr) showPeak(parseInt(tr.getAttribute('data-idx'), 10));
        });
        document.getElementById('peak-modal-close').addEventListener('click', hidePeak);
        document.getElementById('peak-backdrop').addEventListener('click', hidePeak);
    });
})();
</script>
@endpush
