<?php

namespace App\Enums;

enum DocumentKind: string
{
    case DriversLicence = 'drivers_licence';
    case InsuranceCertificate = 'insurance_certificate';

    public function label(): string
    {
        return match ($this) {
            self::DriversLicence => 'Driver’s licence',
            self::InsuranceCertificate => 'Insurance certificate',
        };
    }
}
