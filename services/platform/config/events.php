<?php

return [
    // Redis Stream that carries every domain event of this service (consumers use groups, idempotent by event_id).
    'stream' => env('EVENTS_STREAM', 'mestaro.events'),
    // Approximate cap so the stream cannot grow without bound.
    'stream_maxlen' => (int) env('EVENTS_STREAM_MAXLEN', 100000),
];
