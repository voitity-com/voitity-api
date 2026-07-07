<?php

namespace App\Enums;

enum ProfileFactVisibility: string
{
    case Public = 'public';
    case Private = 'private';
    case Internal = 'internal';
}
