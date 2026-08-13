<?php

return [
    'default' => env(
        'PROFILE_DOMAIN_DRIVER',
        env('APP_ENV', 'production') === 'local' ? 'local' : 'cloudfront'
    ),

    'drivers' => [
        'local' => [
            'routing_endpoint' => env('PROFILE_DOMAIN_LOCAL_ROUTING_ENDPOINT', 'profiles.localhost'),
            'public_url_pattern' => env('PROFILE_DOMAIN_LOCAL_PUBLIC_URL_PATTERN', 'http://{hostname}:3001'),
        ],
        'cloudfront' => [
            'region' => env('PROFILE_DOMAIN_AWS_REGION', 'us-east-1'),
            'distribution_id' => env('PROFILE_DOMAIN_CLOUDFRONT_DISTRIBUTION_ID'),
            'connection_group_id' => env('PROFILE_DOMAIN_CLOUDFRONT_CONNECTION_GROUP_ID'),
            'routing_endpoint' => env('PROFILE_DOMAIN_CLOUDFRONT_ROUTING_ENDPOINT'),
            'validation_token_host' => env('PROFILE_DOMAIN_VALIDATION_TOKEN_HOST', 'cloudfront'),
        ],
    ],
];
