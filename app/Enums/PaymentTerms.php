<?php

namespace App\Enums;

enum PaymentTerms: string
{
    case DueOnReceipt = 'due_on_receipt';
    case Net15 = 'net_15';
    case Net30 = 'net_30';
    case Net60 = 'net_60';

    public function label(): string
    {
        return match ($this) {
            self::DueOnReceipt => 'Due on receipt',
            self::Net15 => 'Net 15',
            self::Net30 => 'Net 30',
            self::Net60 => 'Net 60',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $terms) => ['value' => $terms->value, 'label' => $terms->label()],
            self::cases(),
        );
    }
}
