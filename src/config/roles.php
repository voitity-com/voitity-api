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
            'profile:transcribe',
            'voice:write',
            'voice:use',
            'chat:read',
            'insights:read',
            'messages:write',
            'user:write',
            'user:read',
            'avatar:write',
            'avatar:read',
            'products:read',
            'products:write',
            'products:publish',
            'products:import',
            'subscription-limits:read',
            'subscription-plans:read',
            'payments:create',
            'payments:read',
            'support:create',
            'admin.users.view',
            'admin.users.impersonate',
            'admin.users.subscriptions.manage',
            'admin.reports.view',
            'business:read',
            'business:write',
            'business:activate',
            'business:flow:publish',
            'business:keys:manage',
            'business:leads:read',
            'business:leads:write',
            'business:usage:read',
        ],
    ],
    'user' => [
        'abilities' => [
            'test:test',
            'profile:write',
            'profile:read',
            'profile:transcribe',
            'voice:write',
            'voice:use',
            'chat:read',
            'insights:read',
            'messages:write',
            'user:write',
            'user:read',
            'avatar:write',
            'avatar:read',
            'products:read',
            'products:write',
            'products:publish',
            'products:import',
            'subscription-limits:read',
            'subscription-plans:read',
            'payments:create',
            'payments:read',
            'support:create',
        ],
    ],
    'profile' => [
        'abilities' => [
            'test:test',
            'profile:write',
            'profile:read',
            'profile:transcribe',
            'voice:write',
            'voice:use',
            'chat:read',
            'insights:read',
            'messages:write',
            'user:write',
            'user:read',
            'avatar:write',
            'avatar:read',
            'products:read',
            'products:write',
            'products:publish',
            'products:import',
            'support:create',
        ],
    ],
    'api' => [
        'abilities' => [
            'profile:read',
            'chat:read',
            'insights:read',
            'voice:use',
            'messages:write',
            'user:read',
            'avatar:read',
            'products:read',
        ],
    ],
];
