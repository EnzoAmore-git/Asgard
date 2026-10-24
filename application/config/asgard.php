<?php

return [
    'providers' => [
        'telegram' => [
            'client_id' => env('TELEGRAM_CLIENT_ID'),
            'client_secret' => env('TELEGRAM_CLIENT_SECRET'),
            'redirect_uri' => env('TELEGRAM_REDIRECT_URI'),
        ],
        'vk' => [
            'client_id' => env('VK_CLIENT_ID'),
            'redirect_uri' => env('VK_REDIRECT_URI'),
        ],
    ],
];
