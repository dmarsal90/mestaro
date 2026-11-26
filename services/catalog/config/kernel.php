<?php

return [
    // Name of this service: default consumer group and the `service` it reports in tenant provisioning.
    'service' => 'catalog',
    // Catalog's data lives in one database per restaurant, so its events are written (and relayed from) there.
    'tenant_outbox' => true,
];
