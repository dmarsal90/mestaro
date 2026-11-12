<?php

return [
    // Redis Stream that carries every domain event of this service (consumers use groups, idempotent by event_id).
    'stream' => env('EVENTS_STREAM', 'mestaro.events'),
    // Approximate cap so the stream cannot grow without bound.
    // Messages that keep failing end up here after --max-deliveries attempts, with the original fields.
    'dead_stream' => env('EVENTS_DEAD_STREAM', 'mestaro.events.dead'),
    'stream_maxlen' => (int) env('EVENTS_STREAM_MAXLEN', 100000),
];
