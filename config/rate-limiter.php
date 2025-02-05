<?php

return [
    'hourly_request_limit' => 3000,
    'minute_request_limit' => 60,
    'second_request_limit' => 10,

    'redis' => [
        'host'   => '127.0.0.1',
        'port'   => 6379,
        'prefix' => 'rate-limiter',
    ],
];
