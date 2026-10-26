<?php

namespace App\Enums;

/**
 * How the rental is paid. Every rental is prepaid; this is only when and how.
 */
enum RentalPaymentMethod: string
{
    case CardOnFile = 'card_on_file';
    case PayNow = 'pay_now';
    case PaymentLink = 'payment_link';
    case Counter = 'counter';

    public function label(): string
    {
        return match ($this) {
            self::CardOnFile => 'Charge card on file at pickup',
            self::PayNow => 'Charge card on file now',
            self::PaymentLink => 'Send a payment link',
            self::Counter => 'Pay at the counter (cash, cheque, terminal)',
        };
    }
}
