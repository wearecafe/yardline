<?php

namespace App\Enums;

/**
 * How an order gets paid.
 *
 * Account: charged against the organization's credit limit and invoiced on terms.
 * Prepaid: paid in full (card, cash, etc.) before the material leaves the yard.
 */
enum Settlement: string
{
    case Account = 'account';
    case Prepaid = 'prepaid';
}
