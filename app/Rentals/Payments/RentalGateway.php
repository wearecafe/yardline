<?php

namespace App\Rentals\Payments;

use App\Models\Customer;
use App\Models\RentalPayment;
use App\Payments\CheckoutLink;

/**
 * Card payments for rentals: charge a saved card, hold a deposit on it (an
 * authorization, not a charge), capture or release the hold, refund, and
 * hosted payment links that also save the card for the deposit.
 */
interface RentalGateway
{
    /**
     * @param  array<string, string>  $metadata
     * @return string provider reference (payment intent id)
     */
    public function charge(Customer $customer, int $cents, string $description, array $metadata): string;

    /**
     * @param  array<string, string>  $metadata
     * @return string provider reference for the hold
     */
    public function hold(Customer $customer, int $cents, string $description, array $metadata): string;

    public function capture(string $reference, int $cents): void;

    public function release(string $reference): void;

    public function refund(string $reference, int $cents): void;

    /**
     * Hosted page that takes the payment and saves the card.
     */
    public function paymentLink(RentalPayment $payment, string $description, string $returnUrl): CheckoutLink;
}
