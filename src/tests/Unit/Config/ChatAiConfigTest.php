<?php

namespace Tests\Unit\Config;

use Illuminate\Support\Env;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChatAiConfigTest extends TestCase
{
    #[Test]
    public function audio_message_visibility_inherits_public_filesystem_visibility_by_default(): void
    {
        $env = Env::getRepository();

        $env->clear('CHAT_AUDIO_MESSAGES_VISIBILITY');
        $env->set('FILESYSTEM_PUBLIC_VISIBILITY', 'private');

        $config = require base_path('config/chatai.php');

        $this->assertSame('private', $config['audio_messages']['visibility']);

        $env->clear('FILESYSTEM_PUBLIC_VISIBILITY');
    }

    #[Test]
    public function audio_message_visibility_can_be_configured_explicitly(): void
    {
        $env = Env::getRepository();

        $env->set('CHAT_AUDIO_MESSAGES_VISIBILITY', 'public');
        $env->set('FILESYSTEM_PUBLIC_VISIBILITY', 'private');

        $config = require base_path('config/chatai.php');

        $this->assertSame('public', $config['audio_messages']['visibility']);

        $env->clear('CHAT_AUDIO_MESSAGES_VISIBILITY');
        $env->clear('FILESYSTEM_PUBLIC_VISIBILITY');
    }
}
