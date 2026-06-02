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
            <small id="last-update" class="text-muted mr-3"></small>
            <div class="custom-control custom-checkbox d-inline-block mr-3" style="padding-top:3px;">
                <input type="checkbox" class="custom-control-input" id="autorefresh" checked>
                <label class="custom-control-label" for="autorefresh" style="font-size:.85rem;">Auto-actualizar</label>
            </div>
            <button id="refresh-now" class="btn btn-sm btn-primary">
                <i class="fas fa-sync-alt"></i> Actualizar
            </button>
        </div>
    </div>
</header>

{{-- ====================== Tarjetas de recursos en vivo ====================== --}}
<div class="row">
    <div class="col-12 col-md-6 col-xl-3">
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

    <div class="col-12 col-md-6 col-xl-3">
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

    <div class="col-12 col-md-6 col-xl-3">
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

    <div class="col-12 col-md-6 col-xl-3">
        <section class="card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h4 class="mb-0" style="font-size:1rem;"><i class="fas fa-database mr-1 text-info"></i> Base de datos</h4>
                    <strong id="db-conn" class="h4 mb-0">—</strong>
                </div>
                <small class="text-muted d-block">Conexiones activas</small>
                <small class="text-muted">En ejecución: <span id="db-running">—</span> · Slow: <span id="db-slow">—</span></small>
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
                    (requiere el cron <code>php artisan schedule:run</code> activo en el servidor),
                    o puedes forzar uno con <code>php artisan monitor:snapshot</code>.
                </div>
                <div style="position:relative;height:320px;">
                    <canvas id="history-chart"></canvas>
                </div>
            </div>
        </section>
    </div>
</div>

{{-- ====================== Qué está consumiendo ahora ====================== --}}
<div class="row">
    <div class="col-12 col-xl-7">
        <section class="card mb-4">
            <header class="card-header">
                <h2 class="card-title mb-0" style="font-size:1rem;">
                    <i class="fas fa-stream mr-1"></i> Consultas SQL activas
                </h2>
                <p class="card-subtitle text-muted mb-0" style="font-size:.8rem;">Ordenadas por tiempo de ejecución</p>
            </header>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                            <tr>
                                <th style="width:60px;">ID</th>
                                <th style="width:70px;">Tiempo</th>
                                <th style="width:90px;">Estado</th>
                                <th>Consulta</th>
                            </tr>
                        </thead>
                        <tbody id="queries-body">
                            <tr><td colspan="4" class="text-center text-muted">Cargando…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>

    <div class="col-12 col-xl-5">
        <section class="card mb-4">
            <header class="card-header">
                <h2 class="card-title mb-0" style="font-size:1rem;">
                    <i class="fas fa-list-ol mr-1"></i> Procesos del sistema
                </h2>
                <p class="card-subtitle text-muted mb-0" style="font-size:.8rem;">Mayor consumo de CPU / memoria</p>
            </header>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                            <tr>
                                <th style="width:70px;">PID</th>
                                <th>Proceso</th>
                                <th style="width:70px;">CPU%</th>
                                <th style="width:70px;">Mem%</th>
                            </tr>
                        </thead>
                        <tbody id="processes-body">
                            <tr><td colspan="4" class="text-center text-muted">Cargando…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>

{{-- ====================== Picos de saturación ====================== --}}
<div class="row">
    <div class="col-12">
        <section class="card mb-4">
            <header class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="card-title mb-0" style="font-size:1rem;">
                        <i class="fas fa-exclamation-triangle mr-1 text-danger"></i> Momentos de mayor saturación
                    </h2>
                    <p class="card-subtitle text-muted mb-0" style="font-size:.8rem;">
                        Snapshots con mayor uso de CPU. Haz clic en una fila para ver qué consultas/procesos había en ese momento.
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
                                <th style="width:80px;">CPU%</th>
                                <th style="width:80px;">RAM%</th>
                                <th style="width:90px;">Conexiones</th>
                                <th style="width:120px;"></th>
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

{{-- Modal de detalle de un pico (controlado con JS puro, sin depender de bootstrap.js) --}}
<div id="peak-modal" style="display:none;position:fixed;inset:0;z-index:1050;">
    <div id="peak-backdrop" style="position:absolute;inset:0;background:rgba(0,0,0,.5);"></div>
    <div style="position:relative;max-width:800px;margin:5vh auto;background:#fff;border-radius:4px;box-shadow:0 5px 20px rgba(0,0,0,.3);max-height:90vh;display:flex;flex-direction:column;">
        <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
            <h5 class="mb-0">Detalle del pico — <span id="peak-modal-time"></span></h5>
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
        live:    "{{ route('monitoring.live') }}",
        history: "{{ route('monitoring.history') }}",
        peaks:   "{{ route('monitoring.peaks') }}"
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
        bar.className = 'progress-bar ' + (barClass(value) || (barId === 'cpu-bar' ? 'bg-primary' : barId === 'mem-bar' ? 'bg-success' : 'bg-warning'));
    }

    function renderQueries(list) {
        var body = document.getElementById('queries-body');
        if (!list || !list.length) {
            body.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Sin consultas activas</td></tr>';
            return;
        }
        body.innerHTML = list.map(function (q) {
            return '<tr>' +
                '<td>' + esc(q.id) + '</td>' +
                '<td>' + (q.time !== null ? q.time + 's' : '—') + '</td>' +
                '<td><small>' + esc(q.state || '') + '</small></td>' +
                '<td><code style="font-size:.72rem;white-space:normal;word-break:break-word;">' + esc(q.query) + '</code></td>' +
                '</tr>';
        }).join('');
    }

    function renderProcesses(list) {
        var body = document.getElementById('processes-body');
        if (!list || !list.length) {
            body.innerHTML = '<tr><td colspan="4" class="text-center text-muted">No disponible en este entorno</td></tr>';
            return;
        }
        body.innerHTML = list.map(function (p) {
            return '<tr>' +
                '<td>' + esc(p.pid) + '</td>' +
                '<td>' + esc(p.name) + '</td>' +
                '<td>' + (p.cpu !== null && p.cpu !== undefined ? p.cpu : '—') + '</td>' +
                '<td>' + (p.mem !== null && p.mem !== undefined ? p.mem : '—') + '</td>' +
                '</tr>';
        }).join('');
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
                document.getElementById('db-conn').textContent = d.db_connections !== null ? d.db_connections : '—';
                document.getElementById('db-running').textContent = d.db_threads_running !== null ? d.db_threads_running : '—';
                document.getElementById('db-slow').textContent = d.db_slow_queries !== null ? d.db_slow_queries : '—';
                document.getElementById('env-badge').textContent = d.environment || '—';
                document.getElementById('host-badge').textContent = d.hostname || '';
                document.getElementById('last-update').textContent = 'Actualizado: ' + (d.server_time || '');
                renderQueries(d.top_queries);
                renderProcesses(d.top_processes);
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
                { label: 'CPU %',    data: [], borderColor: '#0088CC', backgroundColor: 'rgba(0,136,204,.08)', borderWidth: 2, tension: .3, pointRadius: 0, fill: true },
                { label: 'RAM %',    data: [], borderColor: '#2BAB67', backgroundColor: 'rgba(43,171,103,.05)', borderWidth: 2, tension: .3, pointRadius: 0, fill: true },
                { label: 'Disco %',  data: [], borderColor: '#E8A100', borderWidth: 2, tension: .3, pointRadius: 0, fill: false },
                { label: 'Conexiones DB', data: [], borderColor: '#A14ED8', borderWidth: 2, tension: .3, pointRadius: 0, fill: false, yAxisID: 'y2' }
            ]},
            options: {
                responsive: true, maintainAspectRatio: false, animation: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y:  { beginAtZero: true, max: 100, title: { display: true, text: '%' } },
                    y2: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Conexiones' } }
                },
                plugins: { legend: { position: 'top' } }
            }
        });
    }

    function loadHistory() {
        var hours = document.getElementById('range-select').value;
        fetch(routes.history + '?hours=' + hours, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var empty = document.getElementById('history-empty');
                empty.style.display = (d.points && d.points.length) ? 'none' : 'block';
                var labels = d.points.map(function (p) {
                    var dt = new Date(p.t);
                    return dt.toLocaleString();
                });
                chart.data.labels = labels;
                chart.data.datasets[0].data = d.points.map(function (p) { return p.cpu; });
                chart.data.datasets[1].data = d.points.map(function (p) { return p.mem; });
                chart.data.datasets[2].data = d.points.map(function (p) { return p.disk; });
                chart.data.datasets[3].data = d.points.map(function (p) { return p.connections; });
                chart.update();
            });
    }

    /* ----------------------- Picos de saturación ----------------------- */
    var peaksCache = [];
    function loadPeaks() {
        var hours = document.getElementById('peaks-range').value;
        fetch(routes.peaks + '?hours=' + hours, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function (r) { return r.json(); })
            .then(function (d) {
                peaksCache = d.top_cpu || [];
                var body = document.getElementById('peaks-body');
                if (!peaksCache.length) {
                    body.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Sin datos en el rango seleccionado</td></tr>';
                    return;
                }
                body.innerHTML = peaksCache.map(function (p, i) {
                    return '<tr style="cursor:pointer;" data-idx="' + i + '">' +
                        '<td>' + esc(p.at) + '</td>' +
                        '<td><span class="badge ' + (p.cpu >= 90 ? 'badge-danger' : p.cpu >= 75 ? 'badge-warning' : 'badge-secondary') + '">' + pct(p.cpu) + '</span></td>' +
                        '<td>' + pct(p.mem) + '</td>' +
                        '<td>' + (p.connections !== null ? p.connections : '—') + '</td>' +
                        '<td><span class="text-primary">Ver detalle</span></td>' +
                        '</tr>';
                }).join('');
            });
    }

    function showPeak(idx) {
        var p = peaksCache[idx];
        if (!p) return;
        document.getElementById('peak-modal-time').textContent = p.at;
        var html = '';

        html += '<h6 class="mb-2">Consultas SQL en ejecución</h6>';
        if (p.top_queries && p.top_queries.length) {
            html += '<div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>ID</th><th>Tiempo</th><th>Estado</th><th>Consulta</th></tr></thead><tbody>';
            html += p.top_queries.map(function (q) {
                return '<tr><td>' + esc(q.id) + '</td><td>' + (q.time !== null ? q.time + 's' : '—') + '</td><td><small>' + esc(q.state || '') + '</small></td>' +
                    '<td><code style="font-size:.72rem;white-space:normal;word-break:break-word;">' + esc(q.query) + '</code></td></tr>';
            }).join('');
            html += '</tbody></table></div>';
        } else {
            html += '<p class="text-muted">No se registraron consultas activas en ese momento.</p>';
        }

        html += '<h6 class="mb-2 mt-3">Procesos del sistema</h6>';
        if (p.top_processes && p.top_processes.length) {
            html += '<div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>PID</th><th>Proceso</th><th>CPU%</th><th>Mem%</th></tr></thead><tbody>';
            html += p.top_processes.map(function (pr) {
                return '<tr><td>' + esc(pr.pid) + '</td><td>' + esc(pr.name) + '</td><td>' + (pr.cpu !== null && pr.cpu !== undefined ? pr.cpu : '—') + '</td><td>' + (pr.mem !== null && pr.mem !== undefined ? pr.mem : '—') + '</td></tr>';
            }).join('');
            html += '</tbody></table></div>';
        } else {
            html += '<p class="text-muted">No se registraron procesos en ese momento.</p>';
        }

        document.getElementById('peak-modal-body').innerHTML = html;
        document.getElementById('peak-modal').style.display = 'block';
    }

    function hidePeak() {
        document.getElementById('peak-modal').style.display = 'none';
    }

    /* ----------------------- Auto-refresh ----------------------- */
    var timer = null;
    function startTimer() {
        stopTimer();
        timer = setInterval(loadLive, 10000); // cada 10 s
    }
    function stopTimer() { if (timer) { clearInterval(timer); timer = null; } }

    document.addEventListener('DOMContentLoaded', function () {
        initChart();
        loadLive();
        loadHistory();
        loadPeaks();
        startTimer();

        document.getElementById('refresh-now').addEventListener('click', function () {
            loadLive(); loadHistory(); loadPeaks();
        });
        document.getElementById('autorefresh').addEventListener('change', function () {
            this.checked ? startTimer() : stopTimer();
        });
        document.getElementById('range-select').addEventListener('change', loadHistory);
        document.getElementById('peaks-range').addEventListener('change', loadPeaks);
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
