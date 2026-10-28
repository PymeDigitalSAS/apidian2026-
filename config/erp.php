<?php
/** Puente optativo: no activa empresas ni cambia ambientes de la API existente. */
return [
    'enabled' => env('ERP_BRIDGE_ENABLED', false),
    'production_enabled' => env('ERP_BRIDGE_PRODUCTION_ENABLED', false),
    'allowed_nits' => array_filter(array_map('trim', explode(',', env('ERP_BRIDGE_ALLOWED_NITS', '')))),
];
