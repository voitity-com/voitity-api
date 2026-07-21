<?php

return [
    'default' => env('PAYMENT_DRIVER', 'wompi'),

    'display_currency' => env('PAYMENTS_DISPLAY_CURRENCY', 'USD'),
    'processing_currency' => env('PAYMENTS_PROCESSING_CURRENCY', 'COP'),
    'usd_cop_rate' => (float) env('PAYMENTS_USD_COP_RATE', 4000),
    'usd_cop_rate_cache_ttl' => (int) env('PAYMENTS_USD_COP_RATE_CACHE_TTL', 14400),
    'redirect_url' => env('PAYMENTS_REDIRECT_URL'),
    'checkout_expires_in_minutes' => (int) env('PAYMENTS_CHECKOUT_EXPIRES_IN_MINUTES', 60),
    'pending_charge_poll_attempts' => (int) env('PAYMENTS_PENDING_CHARGE_POLL_ATTEMPTS', 3),
    'pending_charge_poll_delay_ms' => (int) env('PAYMENTS_PENDING_CHARGE_POLL_DELAY_MS', 500),

    'exchange_rates' => [
        'usd_cop' => [
            'driver' => env('PAYMENTS_USD_COP_RATE_DRIVER', 'datos_gov'),
            'fallback_driver' => env('PAYMENTS_USD_COP_RATE_FALLBACK_DRIVER', 'dolar_api'),
            'manual_rate' => (float) env('PAYMENTS_USD_COP_RATE', 4000),
            'min' => (float) env('PAYMENTS_USD_COP_RATE_MIN', 2000),
            'max' => (float) env('PAYMENTS_USD_COP_RATE_MAX', 8000),
            'stale_cache_ttl' => (int) env('PAYMENTS_USD_COP_RATE_STALE_CACHE_TTL', 300),
            'cache_key' => env('PAYMENTS_USD_COP_RATE_CACHE_KEY', 'payments:usd_cop_rate:current'),
            'last_known_cache_key' => env('PAYMENTS_USD_COP_RATE_LAST_KNOWN_CACHE_KEY', 'payments:usd_cop_rate:last_known'),
        ],
    ],

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

    'usd_cop_rate_drivers' => [
        'datos_gov' => [
            'base_url' => env('PAYMENTS_USD_COP_DATOS_GOV_BASE_URL', 'https://www.datos.gov.co'),
            'resource_id' => env('PAYMENTS_USD_COP_DATOS_GOV_RESOURCE_ID', '32sa-8pi3'),
            'timeout' => (int) env('PAYMENTS_USD_COP_DATOS_GOV_TIMEOUT', 5),
        ],
        'dolar_api' => [
            'url' => env('PAYMENTS_USD_COP_DOLAR_API_URL', 'https://co.dolarapi.com/v1/trm'),
            'timeout' => (int) env('PAYMENTS_USD_COP_DOLAR_API_TIMEOUT', 5),
        ],
        'config' => [
            'rate' => env('PAYMENTS_USD_COP_RATE'),
        ],
    ],
];
