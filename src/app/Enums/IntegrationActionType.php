<?php

namespace App\Enums;

enum IntegrationActionType: string
{
    case Book = 'book';
    case Contact = 'contact';
    case Download = 'download';
    case ListenOn = 'listen_on';
    case ListenTo = 'listen_to';
    case OpenIn = 'open_in';
    case ReadOn = 'read_on';
    case ShopOn = 'shop_on';
    case View = 'view';
    case ViewOn = 'view_on';
    case Visit = 'visit';
}
