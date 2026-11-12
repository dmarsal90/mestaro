<?php

return [
    // Redis Stream that carries every domain event (consumers use groups, idempotent by event_id).
    'stream' => env('EVENTS_STREAM', 'mestaro.events'),
    // Messages that keep failing end up here after --max-deliveries attempts, with the original fields.
    'dead_stream' => env('EVENTS_DEAD_STREAM', 'mestaro.events.dead'),
    // Approximate cap so the stream cannot grow without bound.
    'stream_maxlen' => (int) env('EVENTS_STREAM_MAXLEN', 100000),
];
