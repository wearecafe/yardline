<?php

namespace App\Rentals\Payments;

use App\Models\Customer;
use App\Models\RentalPayment;
use App\Payments\CheckoutLink;
use Illuminate\Support\Str;

/**
 * Local development, tests and the demo yard: every card works, and payment
 * links pay themselves when opened.
 */
class FakeRentalGateway implements RentalGateway
{
    public function charge(Customer $customer, int $cents, string $description, array $metadata): string
    {
        $this->ensureCard($customer);

        return 'pi_fake_'.Str::random(20);
    }

    public function hold(Customer $customer, int $cents, string $description, array $metadata): string
    {
        $this->ensureCard($customer);

        return 'pi_fake_hold_'.Str::random(16);
    }

    public function capture(string $reference, int $cents): void {}

    public function release(string $reference): void {}

    public function refund(string $reference, int $cents): void {}

    public function paymentLink(RentalPayment $payment, string $description, string $returnUrl): CheckoutLink
    {
        $session = 'cs_fake_rental_'.Str::random(20);

        return new CheckoutLink($session, route('rentals.fake-checkout', ['session' => $session]));
    }

    private function ensureCard(Customer $customer): void
    {
        if ($customer->card_last4 === null) {
            throw new CardDeclined("{$customer->full_name} has no card on file.");
        }
    }
}
