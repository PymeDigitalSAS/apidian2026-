{{-- Badge de ambiente DIAN del tipo de documento (1 = Producción, 2 = Habilitación).
     Recibe 'environmentStatus' (getEnvironmentStatus del ProductionController) o,
     en su defecto, 'company' + 'type' (invoice|pos|support|payroll|event) y lo
     resuelve desde las columnas de la empresa. Sin datos asume Habilitación. --}}
@php
    if (isset($environmentStatus['environment_id'])) {
        $envBadgeId = (int) $environmentStatus['environment_id'];
    } elseif (isset($company)) {
        $envBadgeColumns = [
            'invoice' => 'type_environment_id',
            'pos'     => 'eqdocs_type_environment_id',
            'support' => 'support_document_type_environment_id',
            'payroll' => 'payroll_type_environment_id',
            'event'   => 'event_type_environment_id',
        ];
        $envBadgeColumn = $envBadgeColumns[$type ?? 'invoice'] ?? 'type_environment_id';
        $envBadgeId = (int) ($company->{$envBadgeColumn} ?? 2);
    } else {
        $envBadgeId = 2;
    }
    $envBadgeIsProd = $envBadgeId === 1;
@endphp
{{-- bg-* (Bootstrap 5) + badge-* (Bootstrap 4) para cubrir ambas vistas del proyecto --}}
<span class="badge {{ $envBadgeIsProd ? 'bg-success badge-success' : 'bg-warning badge-warning' }} ms-2 ml-2" style="font-size:.7rem; vertical-align:middle;">
    Ambiente: {{ $envBadgeIsProd ? 'Producción' : 'Habilitación' }}
</span>
