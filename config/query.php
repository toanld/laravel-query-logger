<?php
/**
 * Created by Lê Đình Toản.
 * Date: 8/9/2018
 * Time: 11:00 AM
 */
return [
    'enabled' => true,
    'log_query' =>true,
    'query_path' => storage_path('logs/query_logger.log'),
    'slow_path' => storage_path('logs/query_slow.log'),
    'time_slow' => floatval(env("QUERY_SLOW_TIME"))
];