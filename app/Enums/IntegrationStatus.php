<?php

namespace App\Enums;

enum IntegrationStatus: string
{
    case Connected = 'connected';
    case Paused = 'paused';
    case Error = 'error';
}
