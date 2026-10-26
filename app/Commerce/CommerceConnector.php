<?php

namespace App\Commerce;

use App\Enums\IntegrationProvider;
use App\Models\Customer;
use App\Models\ExternalReference;
use App\Models\Product;

/**
 * An outside storefront that mirrors Yardline's commerce data.
 *
 * Yardline owns products, pricing, organizations and customers and pushes
 * them out. Orders placed in the storefront come back in via webhooks.
 *
 * Each push returns the record's ID in the outside system, or null when the
 * record should not exist there (e.g. rental equipment).
 */
interface CommerceConnector
{
    public function provider(): IntegrationProvider;

    public function pushProduct(Product $product, ?ExternalReference $reference): ?string;

    public function pushCustomer(Customer $customer, ?ExternalReference $reference): ?string;
}
