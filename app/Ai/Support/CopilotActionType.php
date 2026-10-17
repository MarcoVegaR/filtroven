<?php

namespace App\Ai\Support;

enum CopilotActionType: string
{
    case Activate = 'activate';
    case Deactivate = 'deactivate';
    case SendReset = 'send_reset';
    case CreateUser = 'create_user';
}
