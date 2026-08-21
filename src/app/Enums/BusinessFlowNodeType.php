<?php

namespace App\Enums;

enum BusinessFlowNodeType: string
{
    case Instruction = 'instruction';
    case Decision = 'decision';
    case Action = 'action';
}
