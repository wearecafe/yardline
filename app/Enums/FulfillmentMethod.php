<?php

namespace App\Enums;

enum FulfillmentMethod: string
{
    case Pickup = 'pickup';
    case Delivery = 'delivery';
}
