<?php

namespace App\Enums;

enum ProfileInsightEventType: string
{
    case ProfileViewed = 'profile_viewed';
    case ProductShown = 'product_shown';
    case ProductClicked = 'product_clicked';
    case MediaShown = 'media_shown';
    case MediaOpened = 'media_opened';
    case MediaExternalClicked = 'media_external_clicked';
    case SocialLinkClicked = 'social_link_clicked';
}
