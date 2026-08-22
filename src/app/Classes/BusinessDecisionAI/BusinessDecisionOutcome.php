<?php

namespace App\Classes\BusinessDecisionAI;

enum BusinessDecisionOutcome: string
{
    case Yes = 'yes';
    case No = 'no';
    case Unclear = 'unclear';
}
