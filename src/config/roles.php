<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Roles and scopes
    |--------------------------------------------------------------------------
    |
    | Here you may configure the abilities by role.
    | This data is set in this config file, but It would be possible
    | manage it from a different source as a database.
    |
    */

    'admin' => [
        'abilities' => [
            'test:test',
            'profile:write',
            'profile:read',
            'voice:write',
            'voice:use',
            'chat:read',
            'messages:write',
            'user:write',
            'user:read',
            'avatar:write',
            'avatar:read',
            'subscription-limits:read',
            'subscription-plans:read',
            'payments:create',
            'payments:read',
        ],
    ],
    'user' => [
        'abilities' => [
            'test:test',
            'profile:write',
            'profile:read',
            'voice:write',
            'voice:use',
            'chat:read',
            'messages:write',
            'user:write',
            'user:read',
            'avatar:write',
            'avatar:read',
            'subscription-limits:read',
            'subscription-plans:read',
            'payments:create',
            'payments:read',
        ],
    ],
    'profile' => [
        'abilities' => [
            'test:test',
            'profile:write',
            'profile:read',
            'voice:write',
            'voice:use',
            'chat:read',
            'messages:write',
            'user:write',
            'user:read',
            'avatar:write',
            'avatar:read',
        ],
    ],
    'api' => [
        'abilities' => [
            'profile:read',
            'chat:read',
            'voice:use',
            'messages:write',
            'user:read',
            'avatar:read',
        ],
    ],
];
