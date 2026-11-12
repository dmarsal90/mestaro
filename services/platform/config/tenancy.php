<?php

return [
    // Services that must report a successful provisioning before a restaurant becomes active.
    // Add catalog/operations/billing here as they start answering TenantRegistered.
    'services' => array_values(array_filter(explode(',', (string) env('TENANCY_SERVICES', 'platform')))),
];
