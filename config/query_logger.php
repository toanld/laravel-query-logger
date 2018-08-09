<?php
return [
    'enabled' => null,
    'query_path' => storage_path('logs/query_logger.log'),
    'slow_path' => storage_path('logs/query_slow.log'),
    'time_slow' => env("QUERY_SLOW_TIME")
];