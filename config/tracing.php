<?php

return [
    'enabled' => (bool) env('TRACING_ENABLED', false),
    'host' => env('TRACING_HOST'),
    'port' => env('TRACING_PORT', 6831),
    'service' => [
        'name' => env('TRACING_SERVICE_NAME', config('app.name'))
    ],
];
