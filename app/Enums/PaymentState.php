<?php

namespace App\Enums;

enum PaymentState: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
