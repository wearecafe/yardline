<?php

namespace App\Enums;

enum PaymentTender: string
{
    case StripeCheckout = 'stripe_checkout';
    case CardTerminal = 'card_terminal';
    case Cash = 'cash';
    case Cheque = 'cheque';
    case ETransfer = 'etransfer';
    case CreditNote = 'credit_note';

    public function label(): string
    {
        return match ($this) {
            self::StripeCheckout => 'Card (payment link)',
            self::CardTerminal => 'Card (terminal)',
            self::Cash => 'Cash',
            self::Cheque => 'Cheque',
            self::ETransfer => 'e-Transfer',
            self::CreditNote => 'Account credit',
        };
    }

    /**
     * Tenders staff can record by hand at the counter.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function manualOptions(): array
    {
        return array_map(
            fn (self $tender) => ['value' => $tender->value, 'label' => $tender->label()],
            [self::CardTerminal, self::Cash, self::Cheque, self::ETransfer],
        );
    }
}
