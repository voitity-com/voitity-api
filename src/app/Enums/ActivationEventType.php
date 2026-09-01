<?php

namespace App\Enums;

enum ActivationEventType: string
{
    case TrialStarted = 'trial_started';
    case ProfileCreated = 'profile_created';
    case AvatarAdded = 'avatar_added';
    case SourceSynchronized = 'source_synchronized';
    case ProfilePublished = 'profile_published';
    case WhatsappAdded = 'whatsapp_added';
    case ProductCreated = 'product_created';
    case ConversationStarted = 'conversation_started';
    case LinkCopied = 'link_copied';

    /** @return list<self> */
    public static function funnel(): array
    {
        return [
            self::TrialStarted,
            self::ProfileCreated,
            self::AvatarAdded,
            self::SourceSynchronized,
            self::ProfilePublished,
            self::WhatsappAdded,
            self::ProductCreated,
            self::ConversationStarted,
            self::LinkCopied,
        ];
    }

    /** @return list<self> */
    public static function commercialActivation(): array
    {
        return [
            self::ProfilePublished,
            self::WhatsappAdded,
            self::ProductCreated,
            self::ConversationStarted,
            self::LinkCopied,
        ];
    }
}
