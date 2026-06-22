<?php

return [
    'default' => env('PAYMENT_DRIVER', 'wompi'),

    'display_currency' => env('PAYMENTS_DISPLAY_CURRENCY', 'USD'),
    'processing_currency' => env('PAYMENTS_PROCESSING_CURRENCY', 'COP'),
    'usd_cop_rate' => (float) env('PAYMENTS_USD_COP_RATE', 4000),
    'redirect_url' => env('PAYMENTS_REDIRECT_URL'),

    'drivers' => [
        'wompi' => [
            'environment' => env('WOMPI_ENV', 'sandbox'),
            'public_key' => env('WOMPI_PUBLIC_KEY'),
            'private_key' => env('WOMPI_PRIVATE_KEY'),
            'integrity_secret' => env('WOMPI_INTEGRITY_SECRET'),
            'events_secret' => env('WOMPI_EVENTS_SECRET'),
            'checkout_url' => env('WOMPI_CHECKOUT_URL', 'https://checkout.wompi.co/p/'),
            'widget_url' => env('WOMPI_WIDGET_URL', 'https://checkout.wompi.co/widget.js'),
            'api_url' => env('WOMPI_API_URL', 'https://sandbox.wompi.co/v1'),
        ],
    ],
];
