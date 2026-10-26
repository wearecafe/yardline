<?php

namespace App\Enums;

enum RentalTender: string
{
    case CardOnFile = 'card_on_file';
    case PaymentLink = 'payment_link';
    case CardTerminal = 'card_terminal';
    case Cash = 'cash';
    case Cheque = 'cheque';
    case ETransfer = 'etransfer';

    public function label(): string
    {
        return match ($this) {
            self::CardOnFile => 'Card on file',
            self::PaymentLink => 'Card (payment link)',
            self::CardTerminal => 'Card (terminal)',
            self::Cash => 'Cash',
            self::Cheque => 'Cheque',
            self::ETransfer => 'e-Transfer',
        };
    }
}
