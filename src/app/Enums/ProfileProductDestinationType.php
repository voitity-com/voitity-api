<?php

namespace App\Enums;

enum ProfileProductDestinationType: string
{
    case ExternalUrl = 'external_url';
    case Telegram = 'telegram';
    case WhatsApp = 'whatsapp';
}
