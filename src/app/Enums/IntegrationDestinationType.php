<?php

namespace App\Enums;

enum IntegrationDestinationType: string
{
    case Amazon = 'amazon';
    case AppleMusic = 'apple_music';
    case Application = 'application';
    case Behance = 'behance';
    case Blog = 'blog';
    case Booking = 'booking';
    case ContactPage = 'contact_page';
    case Course = 'course';
    case Discord = 'discord';
    case Document = 'document';
    case Event = 'event';
    case Facebook = 'facebook';
    case Github = 'github';
    case Instagram = 'instagram';
    case Linkedin = 'linkedin';
    case Marketplace = 'marketplace';
    case Medium = 'medium';
    case Membership = 'membership';
    case MercadoLibre = 'mercado_libre';
    case Magazine = 'magazine';
    case Newsletter = 'newsletter';
    case NewsMedia = 'news_media';
    case OnlineStore = 'online_store';
    case Other = 'other';
    case Pinterest = 'pinterest';
    case Podcast = 'podcast';
    case Portfolio = 'portfolio';
    case Reddit = 'reddit';
    case Snapchat = 'snapchat';
    case Spotify = 'spotify';
    case Substack = 'substack';
    case Telegram = 'telegram';
    case Threads = 'threads';
    case Tiktok = 'tiktok';
    case Twitch = 'twitch';
    case Website = 'website';
    case Whatsapp = 'whatsapp';
    case X = 'x';
    case Youtube = 'youtube';
}
