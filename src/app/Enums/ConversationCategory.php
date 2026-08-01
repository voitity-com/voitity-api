<?php

namespace App\Enums;

enum ConversationCategory: string
{
    case IrrelevantOrSpam = 'irrelevant_or_spam';
    case ProfileDiscovery = 'profile_discovery';
    case SocialEngagement = 'social_engagement';
    case ProductInterest = 'product_interest';
    case PurchaseIntent = 'purchase_intent';
    case BusinessOpportunity = 'business_opportunity';
    case SupportOrComplaint = 'support_or_complaint';
    case OtherOrUnclear = 'other_or_unclear';
}
