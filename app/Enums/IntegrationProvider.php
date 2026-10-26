<?php

namespace App\Enums;

enum IntegrationProvider: string
{
    case Shopify = 'shopify';
    case QuickBooks = 'quickbooks';

    /**
     * Storefronts that products, prices and stock are pushed to.
     *
     * @return list<self>
     */
    public static function commerce(): array
    {
        return [self::Shopify];
    }
}
