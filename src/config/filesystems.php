<?php

$publicDriver = env('FILESYSTEM_PUBLIC_DRIVER', 'local');
$publicRoot = env(
    'FILESYSTEM_PUBLIC_ROOT',
    strtolower((string) $publicDriver) === 's3' ? '' : storage_path('app/public')
);

$profilesDriver = env('FILESYSTEM_PROFILES_DRIVER', $publicDriver);
$profilesRoot = env(
    'FILESYSTEM_PROFILES_ROOT',
    strtolower((string) $profilesDriver) === 's3' ? '' : storage_path('app/public')
);

// BucketOwnerEnforced accepts this canned ACL while keeping objects private behind CloudFront.
$s3UploadOptions = static fn (mixed $driver): array => strtolower((string) $driver) === 's3'
    ? ['ACL' => 'bucket-owner-full-control']
    : [];

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => $publicDriver,
            'root' => $publicRoot,
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_PUBLIC_BUCKET', env('AWS_BUCKET')),
            'url' => env('FILESYSTEM_PUBLIC_URL', env('APP_URL').'/storage'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => env('FILESYSTEM_PUBLIC_VISIBILITY', 'public'),
            'options' => $s3UploadOptions($publicDriver),
            'throw' => false,
            'report' => false,
        ],

        'profiles' => [
            'driver' => $profilesDriver,
            'root' => $profilesRoot,
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_PROFILES_BUCKET', env('AWS_PUBLIC_BUCKET', env('AWS_BUCKET'))),
            'url' => env('AWS_PROFILES_URL', env('FILESYSTEM_PUBLIC_URL', env('APP_URL').'/storage')),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => env('AWS_PROFILES_VISIBILITY', env('FILESYSTEM_PUBLIC_VISIBILITY', 'public')),
            'options' => $s3UploadOptions($profilesDriver),
            'throw' => env('FILESYSTEM_PROFILES_THROW', true),
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
