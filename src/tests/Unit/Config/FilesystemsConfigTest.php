<?php

namespace Tests\Unit\Config;

use Illuminate\Support\Env;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FilesystemsConfigTest extends TestCase
{
    #[Test]
    public function public_disk_keeps_local_storage_root_for_local_driver(): void
    {
        $env = Env::getRepository();

        $env->set('FILESYSTEM_PUBLIC_DRIVER', 'local');
        $env->clear('FILESYSTEM_PUBLIC_ROOT');

        $config = require base_path('config/filesystems.php');

        $this->assertSame('local', $config['disks']['public']['driver']);
        $this->assertSame(storage_path('app/public'), $config['disks']['public']['root']);
        $this->assertSame([], $config['disks']['public']['options']);

        $env->clear('FILESYSTEM_PUBLIC_DRIVER');
    }

    #[Test]
    public function public_disk_uses_empty_root_for_s3_driver_by_default(): void
    {
        $env = Env::getRepository();

        $env->set('FILESYSTEM_PUBLIC_DRIVER', 's3');
        $env->clear('FILESYSTEM_PUBLIC_ROOT');

        $config = require base_path('config/filesystems.php');

        $this->assertSame('s3', $config['disks']['public']['driver']);
        $this->assertSame('', $config['disks']['public']['root']);
        $this->assertSame(
            ['ACL' => 'bucket-owner-full-control'],
            $config['disks']['public']['options']
        );

        $env->clear('FILESYSTEM_PUBLIC_DRIVER');
    }

    #[Test]
    public function public_disk_root_can_be_configured_explicitly(): void
    {
        $env = Env::getRepository();

        $env->set('FILESYSTEM_PUBLIC_DRIVER', 's3');
        $env->set('FILESYSTEM_PUBLIC_ROOT', 'public-assets');

        $config = require base_path('config/filesystems.php');

        $this->assertSame('public-assets', $config['disks']['public']['root']);

        $env->clear('FILESYSTEM_PUBLIC_DRIVER');
        $env->clear('FILESYSTEM_PUBLIC_ROOT');
    }

    #[Test]
    public function profiles_disk_uses_bucket_owner_acl_for_s3_driver(): void
    {
        $env = Env::getRepository();

        $env->set('FILESYSTEM_PUBLIC_DRIVER', 'local');
        $env->set('FILESYSTEM_PROFILES_DRIVER', 's3');
        $env->clear('FILESYSTEM_PROFILES_ROOT');

        $config = require base_path('config/filesystems.php');

        $this->assertSame('s3', $config['disks']['profiles']['driver']);
        $this->assertSame('', $config['disks']['profiles']['root']);
        $this->assertSame(
            ['ACL' => 'bucket-owner-full-control'],
            $config['disks']['profiles']['options']
        );

        $env->clear('FILESYSTEM_PUBLIC_DRIVER');
        $env->clear('FILESYSTEM_PROFILES_DRIVER');
    }
}
