@php
    $resolutionsHab = $resolutionsHab ?? collect();
    $resolutionsProd = $resolutionsProd ?? collect();
    $resolutionTypeDocuments = $resolutionTypeDocuments ?? collect();
    $companyEnvId = (int) ($company->type_environment_id ?? 2);
    $companyEnvLabel = $companyEnvId === 1 ? 'Producción' : 'Habilitación';
@endphp

<div class="p-3">
    <ul class="nav nav-pills mb-3" id="resEnvTabs_{{ $type }}" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="res-hab-tab-{{ $type }}" data-bs-toggle="tab"
                data-bs-target="#res-hab-{{ $type }}" type="button" role="tab"
                aria-controls="res-hab-{{ $type }}" aria-selected="true">
                <i class="fas fa-flask me-1"></i> Habilitación
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="res-prod-tab-{{ $type }}" data-bs-toggle="tab"
                data-bs-target="#res-prod-{{ $type }}" type="button" role="tab"
                aria-controls="res-prod-{{ $type }}" aria-selected="false">
                <i class="fas fa-rocket me-1"></i> Producción
            </button>
        </li>
    </ul>

    <div class="tab-content">
        @foreach (['hab' => ['env_id' => 2, 'data' => $resolutionsHab, 'label' => 'Habilitación'],
                   'prod' => ['env_id' => 1, 'data' => $resolutionsProd, 'label' => 'Producción']] as $key => $info)
            <div class="tab-pane fade {{ $key === 'hab' ? 'show active' : '' }}" id="res-{{ $key }}-{{ $type }}"
                role="tabpanel" aria-labelledby="res-{{ $key }}-tab-{{ $type }}">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h5 class="m-0">Resoluciones en {{ $info['label'] }}</h5>
                    @if ($info['env_id'] === $companyEnvId)
                        <button type="button" class="btn btn-primary btn-sm"
                            data-bs-toggle="modal"
                            data-bs-target="#newResolutionModal_{{ $type }}">
                            <i class="fas fa-plus"></i> Nueva resolución
                        </button>
                    @else
                        <small class="text-muted">
                            <i class="fas fa-info-circle"></i>
                            Solo se pueden crear en el ambiente actual de la empresa ({{ $companyEnvLabel }}).
                        </small>
                    @endif
                </div>

                <div class="table-responsive">
                    <table class="table res-table mb-0">
                        <thead>
                            <tr>
                                <th>Prefijo</th>
                                <th>Número</th>
                                <th>Tipo de documento</th>
                                <th>Fecha</th>
                                <th>Rango</th>
                                <th>Vigencia</th>
                                <th>Clave técnica</th>
                                <th class="text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($info['data'] as $resolution)
                                @php
                                    $fecha = function ($valor) {
                                        if (!$valor) {
                                            return '—';
                                        }
                                        try {
                                            return \Carbon\Carbon::parse($valor)->format('d/m/Y');
                                        } catch (\Throwable $e) {
                                            return $valor;
                                        }
                                    };
                                    $clave = trim((string) $resolution->technical_key);
                                    $claveCorta = strlen($clave) > 18
                                        ? substr($clave, 0, 8).'…'.substr($clave, -6)
                                        : $clave;
                                    $estado = 'sin-fecha';
                                    if ($resolution->date_to) {
                                        try {
                                            $hasta = \Carbon\Carbon::parse($resolution->date_to)->endOfDay();
                                            $estado = $hasta->isPast() ? 'vencida' : ($hasta->lte(now()->addDays(30)) ? 'por-vencer' : 'vigente');
                                        } catch (\Throwable $e) {
                                            $estado = 'sin-fecha';
                                        }
                                    }
                                    $estadoTexto = ['vigente' => 'Vigente', 'por-vencer' => 'Por vencer', 'vencida' => 'Vencida', 'sin-fecha' => ''][$estado];
                                @endphp
                                <tr>
                                    <td><span class="res-prefix">{{ $resolution->prefix }}</span></td>
                                    <td class="res-numero">{{ $resolution->resolution }}</td>
                                    <td class="res-doc">{{ optional($resolution->type_document)->name }}</td>
                                    <td>{{ $fecha($resolution->resolution_date) }}</td>
                                    <td>{{ number_format((int) $resolution->from, 0, ',', '.') }} – {{ number_format((int) $resolution->to, 0, ',', '.') }}</td>
                                    <td>
                                        <span class="res-fechas">{{ $fecha($resolution->date_from) }} → {{ $fecha($resolution->date_to) }}</span>
                                        @if($estadoTexto !== '')
                                            <span class="res-estado res-estado-{{ $estado }}">{{ $estadoTexto }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($clave === '')
                                            <span class="text-muted">—</span>
                                        @else
                                            <span class="res-key" title="{{ $clave }}">{{ $claveCorta }}</span>
                                            <button type="button" class="res-copy" data-copy="{{ $clave }}" title="Copiar clave técnica">
                                                <i class="far fa-copy"></i>
                                            </button>
                                        @endif
                                    </td>
                                    <td class="text-right text-nowrap">
                                        <button type="button" class="btn btn-sm btn-outline-primary edit-resolution-btn-{{ $type }}"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editResolutionModal_{{ $type }}"
                                            data-id="{{ $resolution->id }}"
                                            data-type-document-id="{{ $resolution->type_document_id }}"
                                            data-prefix="{{ $resolution->prefix }}"
                                            data-resolution="{{ $resolution->resolution }}"
                                            data-resolution-date="{{ $resolution->resolution_date }}"
                                            data-technical-key="{{ $resolution->technical_key }}"
                                            data-from="{{ $resolution->from }}"
                                            data-to="{{ $resolution->to }}"
                                            data-date-from="{{ $resolution->date_from }}"
                                            data-date-to="{{ $resolution->date_to }}"
                                            title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger delete-resolution-btn-{{ $type }}"
                                            data-id="{{ $resolution->id }}"
                                            data-prefix="{{ $resolution->prefix }}"
                                            title="Eliminar">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">
                                        No hay resoluciones en {{ $info['label'] }}.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    </div>
</div>

{{-- Modal: Nueva Resolución --}}
<div class="modal fade" id="newResolutionModal_{{ $type }}" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Nueva Resolución ({{ $companyEnvLabel }})</h5>
                <button type="button" class="close" data-bs-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form class="newResolutionForm_{{ $type }}" method="POST"
                action="{{ route('company.resolutions.store', ['company' => $company->identification_number]) }}">
                @csrf
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tipo de Documento</label>
                                <select class="form-control" name="type_document_id">
                                    <option value="">Seleccionar tipo de documento</option>
                                    @foreach ($resolutionTypeDocuments as $td)
                                        <option value="{{ $td->id }}">{{ $td->name }}</option>
                                    @endforeach
                                </select>
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Prefijo</label>
                                <input type="text" class="form-control" name="prefix" maxlength="4">
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Número de Resolución</label>
                                <input type="text" class="form-control" name="resolution">
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Fecha de Resolución</label>
                                <input type="date" class="form-control" name="resolution_date">
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Clave Técnica</label>
                                @if ($type === 'support')
                                    {{-- La DIAN no requiere clave técnica para Documento Soporte: campo bloqueado --}}
                                    <input type="text" class="form-control" name="technical_key" value="" readonly
                                        placeholder="No aplica para Documento Soporte" style="background:#f8f9fa;">
                                    <small class="form-text text-muted">La DIAN no requiere clave técnica para Documento Soporte.</small>
                                @else
                                    <input type="text" class="form-control" name="technical_key">
                                @endif
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Rango Inicial</label>
                                <input type="number" class="form-control" name="from" min="1">
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Rango Final</label>
                                <input type="number" class="form-control" name="to" min="1">
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Vigencia Desde</label>
                                <input type="date" class="form-control" name="date_from">
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Vigencia Hasta</label>
                                <input type="date" class="form-control" name="date_to">
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary saveResolutionBtn_{{ $type }}">
                        <i class="fas fa-save"></i> Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Modal: Editar Resolución --}}
<div class="modal fade" id="editResolutionModal_{{ $type }}" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Editar Resolución</h5>
                <button type="button" class="close" data-bs-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form class="editResolutionForm_{{ $type }}" method="POST" action="">
                @csrf
                @method('PUT')
                <input type="hidden" name="resolution_id">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tipo de Documento</label>
                                <select class="form-control" name="type_document_id">
                                    <option value="">Seleccionar tipo de documento</option>
                                    @foreach ($resolutionTypeDocuments as $td)
                                        <option value="{{ $td->id }}">{{ $td->name }}</option>
                                    @endforeach
                                </select>
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Prefijo</label>
                                <input type="text" class="form-control" name="prefix" readonly style="background:#f8f9fa;">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Número de Resolución</label>
                                <input type="text" class="form-control" name="resolution">
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Fecha de Resolución</label>
                                <input type="date" class="form-control" name="resolution_date">
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Clave Técnica</label>
                                @if ($type === 'support')
                                    {{-- La DIAN no requiere clave técnica para Documento Soporte: campo bloqueado --}}
                                    <input type="text" class="form-control" name="technical_key" value="" readonly
                                        placeholder="No aplica para Documento Soporte" style="background:#f8f9fa;">
                                    <small class="form-text text-muted">La DIAN no requiere clave técnica para Documento Soporte.</small>
                                @else
                                    <input type="text" class="form-control" name="technical_key">
                                @endif
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Rango Inicial</label>
                                <input type="number" class="form-control" name="from" min="1">
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Rango Final</label>
                                <input type="number" class="form-control" name="to" min="1">
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Vigencia Desde</label>
                                <input type="date" class="form-control" name="date_from">
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Vigencia Hasta</label>
                                <input type="date" class="form-control" name="date_to">
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary updateResolutionBtn_{{ $type }}">
                        <i class="fas fa-save"></i> Actualizar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@once
@push('styles')
<style>
    .res-table {
        font-size: 13.5px;
        border-collapse: separate;
        border-spacing: 0;
    }
    .res-table thead th {
        background: #f4f7fb;
        color: #5b6573;
        font-size: 11.5px;
        font-weight: 700;
        letter-spacing: .04em;
        text-transform: uppercase;
        border-bottom: 1px solid #e4e9f0;
        white-space: nowrap;
        padding: 10px 12px;
    }
    .res-table tbody td {
        vertical-align: middle;
        white-space: nowrap;
        padding: 12px;
        border-top: 1px solid #eef1f5;
        color: #2b323d;
    }
    .res-table tbody tr:hover td {
        background: #f8fafc;
    }
    .res-table .res-doc {
        white-space: normal;
        min-width: 150px;
        max-width: 220px;
    }
    .res-prefix {
        display: inline-block;
        background: #e8eefb;
        color: #1e3a6e;
        font-weight: 700;
        letter-spacing: .03em;
        border-radius: 6px;
        padding: 3px 8px;
        font-size: 12px;
    }
    .res-numero {
        font-variant-numeric: tabular-nums;
        font-weight: 600;
    }
    .res-fechas {
        font-variant-numeric: tabular-nums;
    }
    .res-estado {
        display: inline-block;
        margin-left: 8px;
        border-radius: 999px;
        padding: 2px 8px;
        font-size: 11px;
        font-weight: 700;
        vertical-align: middle;
    }
    .res-estado-vigente {
        background: #e7f6ee;
        color: #157347;
    }
    .res-estado-por-vencer {
        background: #fff4d6;
        color: #8a5a00;
    }
    .res-estado-vencida {
        background: #fde8ea;
        color: #b42318;
    }
    .res-key {
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: 12px;
        background: #f3f5f8;
        border-radius: 6px;
        padding: 3px 8px;
        color: #374151;
    }
    .res-copy {
        border: 0;
        background: transparent;
        color: #6b7280;
        padding: 0 4px;
        cursor: pointer;
        vertical-align: middle;
    }
    .res-copy:hover,
    .res-copy.is-copied {
        color: #0d6efd;
    }
</style>
@endpush
@endonce

@push('scripts')
<script>
(function () {
    const TYPE = @json($type);
    const COMPANY = @json($company->identification_number);
    const STORE_URL = "{{ route('company.resolutions.store', ['company' => $company->identification_number]) }}";

    const $newForm = $('.newResolutionForm_' + TYPE);
    const $editForm = $('.editResolutionForm_' + TYPE);
    const newModalEl = document.getElementById('newResolutionModal_' + TYPE);
    const editModalEl = document.getElementById('editResolutionModal_' + TYPE);

    if (!window.__resCopyBound) {
        window.__resCopyBound = true;
        $(document).on('click', '.res-copy', function () {
            const texto = String($(this).attr('data-copy') || '');
            const $btn = $(this);
            const listo = function () {
                $btn.addClass('is-copied').attr('title', 'Copiada');
                setTimeout(function () {
                    $btn.removeClass('is-copied').attr('title', 'Copiar clave técnica');
                }, 1200);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(texto).then(listo);
                return;
            }
            const area = document.createElement('textarea');
            area.value = texto;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.left = '-9999px';
            document.body.appendChild(area);
            area.select();
            document.execCommand('copy');
            document.body.removeChild(area);
            listo();
        });
    }

    const hideModal = (el) => {
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(el).hide();
        } else if (window.jQuery && jQuery(el).modal) {
            jQuery(el).modal('hide');
        }
    };

    function clearErrors($form) {
        $form.find('.form-control').removeClass('is-invalid');
        $form.find('.invalid-feedback').text('').hide();
    }

    function displayErrors($form, errors) {
        $.each(errors, function (field, messages) {
            const $input = $form.find('[name="' + field + '"]');
            $input.addClass('is-invalid');
            $input.siblings('.invalid-feedback').text(messages[0]).show();
        });
    }

    function notify(text, type) {
        if (typeof PNotify !== 'undefined') {
            new PNotify({ text: text, type: type || 'info', delay: 3000 });
        } else {
            alert(text);
        }
    }

    // Reset form al abrir (se re-selecciona por si el form no existía al cargar el script)
    $(newModalEl).on('show.bs.modal', function () {
        const $f = $('.newResolutionForm_' + TYPE);
        if ($f.length) {
            $f[0].reset();
            clearErrors($f);
        }
    });

    // Submit crear (delegado en document: engancha aunque el form se cargue después
    // o el bind directo fallara; usa $(this) para operar sobre el form real enviado).
    $(document).on('submit', '.newResolutionForm_' + TYPE, function (e) {
        e.preventDefault();
        const $form = $(this);
        clearErrors($form);
        const $btn = $form.find('.saveResolutionBtn_' + TYPE);
        const original = $btn.html();
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Guardando...');

        $.ajax({
            url: STORE_URL,
            method: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            success: function (response) {
                if (response.success) {
                    notify(response.message, 'success');
                    hideModal(newModalEl);
                    setTimeout(() => location.reload(), 700);
                } else {
                    notify(response.message || 'Error al crear la resolución', 'error');
                }
            },
            error: function (xhr) {
                if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                    displayErrors($form, xhr.responseJSON.errors);
                    notify('Corrige los errores del formulario', 'error');
                } else {
                    notify((xhr.responseJSON && xhr.responseJSON.message) || 'Error del servidor', 'error');
                }
            },
            complete: function () {
                $btn.prop('disabled', false).html(original);
            }
        });
    });

    $(document).on('click', '.delete-resolution-btn-' + TYPE, function () {
        const $b = $(this);
        const id = $b.data('id');
        const prefix = $b.data('prefix');
        if (!window.confirm('¿Eliminar la resolución ' + prefix + '?')) {
            return;
        }
        $b.prop('disabled', true);
        $.ajax({
            url: '/companies/' + COMPANY + '/configuration/resolutions/' + id,
            method: 'DELETE',
            data: { _token: $('meta[name="csrf-token"]').attr('content') },
            dataType: 'json',
            success: function (response) {
                if (response.success) {
                    notify(response.message, 'success');
                    setTimeout(() => location.reload(), 500);
                } else {
                    notify(response.message || 'No se pudo eliminar la resolución', 'error');
                    $b.prop('disabled', false);
                }
            },
            error: function (xhr) {
                notify((xhr.responseJSON && xhr.responseJSON.message) || 'No se pudo eliminar la resolución', 'error');
                $b.prop('disabled', false);
            }
        });
    });

    // Abrir modal editar — cargar datos (re-selecciona el form por robustez)
    $(document).on('click', '.edit-resolution-btn-' + TYPE, function () {
        const $b = $(this);
        const $form = $('.editResolutionForm_' + TYPE);
        clearErrors($form);
        // Respaldo nativo: si el AJAX no engancha, el submit POST (con _method=PUT) va a la URL correcta.
        $form.attr('action', '/companies/' + COMPANY + '/configuration/resolutions/' + $b.data('id'));
        $form.find('[name="resolution_id"]').val($b.data('id'));
        $form.find('[name="type_document_id"]').val($b.data('type-document-id'));
        $form.find('[name="prefix"]').val($b.data('prefix'));
        $form.find('[name="resolution"]').val($b.data('resolution'));
        $form.find('[name="resolution_date"]').val($b.data('resolution-date'));
        $form.find('[name="technical_key"]').val($b.data('technical-key'));
        $form.find('[name="from"]').val($b.data('from'));
        $form.find('[name="to"]').val($b.data('to'));
        $form.find('[name="date_from"]').val($b.data('date-from'));
        $form.find('[name="date_to"]').val($b.data('date-to'));
    });

    // Submit editar (delegado en document; usa $(this) para el form real)
    $(document).on('submit', '.editResolutionForm_' + TYPE, function (e) {
        e.preventDefault();
        const $form = $(this);
        clearErrors($form);
        const $btn = $form.find('.updateResolutionBtn_' + TYPE);
        const original = $btn.html();
        const resolutionId = $form.find('[name="resolution_id"]').val();
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Actualizando...');

        $.ajax({
            url: '/companies/' + COMPANY + '/configuration/resolutions/' + resolutionId,
            method: 'PUT',
            data: $form.serialize(),
            dataType: 'json',
            success: function (response) {
                if (response.success) {
                    notify(response.message, 'success');
                    hideModal(editModalEl);
                    setTimeout(() => location.reload(), 700);
                } else {
                    notify(response.message || 'Error al actualizar la resolución', 'error');
                }
            },
            error: function (xhr) {
                if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                    displayErrors($form, xhr.responseJSON.errors);
                    notify('Corrige los errores del formulario', 'error');
                } else {
                    notify((xhr.responseJSON && xhr.responseJSON.message) || 'Error del servidor', 'error');
                }
            },
            complete: function () {
                $btn.prop('disabled', false).html(original);
            }
        });
    });
})();
</script>
@endpush
