<?php

namespace App\Rentals;

use App\Enums\FuelLevel;
use Illuminate\Http\UploadedFile;

/**
 * What staff record for one unit at check-out or return.
 */
final readonly class InspectionData
{
    /**
     * @param  list<array{file: UploadedFile, label: string|null, new_damage: bool}>  $photos
     */
    public function __construct(
        public int $rentalItemId,
        public ?float $hourMeter,
        public ?FuelLevel $fuel,
        public bool $clean,
        public ?string $notes,
        public array $photos = [],
        public ?string $damageDescription = null,
        public int $damageCents = 0,
        public bool $damageCoveredByWaiver = false,
    ) {}
}
