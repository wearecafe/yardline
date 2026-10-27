<?php

namespace App\Time;

use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;

/**
 * How a business keeps time, in tenants.settings['time']. One set of
 * rules covers every employee, seeded from the province's defaults.
 *
 * The provincial defaults are the general standards (Ontario's
 * Employment Standards Act, Quebec's Act respecting labour standards):
 * check them against the current rules and any averaging agreement.
 */
final readonly class TimeSettings
{
    public const PROVINCES = ['ON' => 'Ontario', 'QC' => 'Quebec'];

    public const FREQUENCIES = ['weekly' => 'Weekly', 'biweekly' => 'Every two weeks', 'semimonthly' => 'Twice a month (1st and 16th)'];

    public const PHOTO = ['off' => 'Off', 'everyone' => 'Everyone', 'selected' => 'Selected people'];

    public const WEEKDAYS = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];

    /**
     * @param  int  $weekStartsOn  0 = Sunday … 6 = Saturday; the workweek overtime is counted over
     * @param  int  $roundingMinutes  0 for none; otherwise in and out round to the nearest step
     */
    public function __construct(
        public bool $enabled = false,
        public string $province = 'ON',
        public int $weeklyOvertimeHours = 44,
        public float $overtimeRate = 1.5,
        public int $mealAfterHours = 5,
        public int $mealMinutes = 30,
        public int $longShiftHours = 12,
        public int $dailyRestHours = 11,
        public bool $checkDailyRest = true,
        public int $roundingMinutes = 0,
        public bool $autoMealDeduction = false,
        public string $frequency = 'biweekly',
        public string $anchor = '2026-01-04',
        public int $weekStartsOn = 0,
        public string $photo = 'off',
        public int $geofenceM = 300,
        public string $adpCompanyCode = '',
        public string $adpBatchId = 'YL',
    ) {}

    /**
     * The defaults for a province.
     */
    public static function forProvince(string $province): self
    {
        return match ($province) {
            'QC' => new self(province: 'QC', weeklyOvertimeHours: 40, checkDailyRest: false),
            default => new self(province: 'ON'),
        };
    }

    public static function current(): self
    {
        return self::for(CurrentTenant::get());
    }

    public static function for(?Tenant $tenant): self
    {
        $v = $tenant?->settings['time'] ?? null;

        if (! is_array($v)) {
            return self::forProvince(self::provinceOf($tenant));
        }

        $province = isset(self::PROVINCES[$v['province'] ?? '']) ? (string) $v['province'] : 'ON';
        $defaults = self::forProvince($province);
        $frequency = (string) ($v['frequency'] ?? $defaults->frequency);
        $photo = (string) ($v['photo'] ?? 'off');

        return new self(
            enabled: (bool) ($v['enabled'] ?? false),
            province: $province,
            weeklyOvertimeHours: max(1, (int) ($v['weekly_overtime_hours'] ?? $defaults->weeklyOvertimeHours)),
            overtimeRate: (float) ($v['overtime_rate'] ?? $defaults->overtimeRate),
            mealAfterHours: max(1, (int) ($v['meal_after_hours'] ?? $defaults->mealAfterHours)),
            mealMinutes: max(0, (int) ($v['meal_minutes'] ?? $defaults->mealMinutes)),
            longShiftHours: max(1, (int) ($v['long_shift_hours'] ?? $defaults->longShiftHours)),
            dailyRestHours: max(0, (int) ($v['daily_rest_hours'] ?? $defaults->dailyRestHours)),
            checkDailyRest: (bool) ($v['check_daily_rest'] ?? $defaults->checkDailyRest),
            roundingMinutes: in_array((int) ($v['rounding_minutes'] ?? 0), [0, 5, 6, 10, 15], true) ? (int) ($v['rounding_minutes'] ?? 0) : 0,
            autoMealDeduction: (bool) ($v['auto_meal_deduction'] ?? false),
            frequency: isset(self::FREQUENCIES[$frequency]) ? $frequency : 'biweekly',
            anchor: self::validDate($v['anchor'] ?? null) ?? $defaults->anchor,
            weekStartsOn: min(6, max(0, (int) ($v['week_starts_on'] ?? 0))),
            photo: isset(self::PHOTO[$photo]) ? $photo : 'off',
            geofenceM: max(50, (int) ($v['geofence_m'] ?? 300)),
            adpCompanyCode: (string) ($v['adp_company_code'] ?? ''),
            adpBatchId: (string) ($v['adp_batch_id'] ?? 'YL'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'province' => $this->province,
            'weekly_overtime_hours' => $this->weeklyOvertimeHours,
            'overtime_rate' => $this->overtimeRate,
            'meal_after_hours' => $this->mealAfterHours,
            'meal_minutes' => $this->mealMinutes,
            'long_shift_hours' => $this->longShiftHours,
            'daily_rest_hours' => $this->dailyRestHours,
            'check_daily_rest' => $this->checkDailyRest,
            'rounding_minutes' => $this->roundingMinutes,
            'auto_meal_deduction' => $this->autoMealDeduction,
            'frequency' => $this->frequency,
            'anchor' => $this->anchor,
            'week_starts_on' => $this->weekStartsOn,
            'photo' => $this->photo,
            'geofence_m' => $this->geofenceM,
            'adp_company_code' => $this->adpCompanyCode,
            'adp_batch_id' => $this->adpBatchId,
        ];
    }

    /**
     * The first day of the workweek a date falls in.
     */
    public function weekStart(CarbonImmutable $date): CarbonImmutable
    {
        $back = ($date->dayOfWeek - $this->weekStartsOn + 7) % 7;

        return $date->startOfDay()->subDays($back);
    }

    /**
     * Where the business's first yard is, as a starting province.
     */
    private static function provinceOf(?Tenant $tenant): string
    {
        $region = $tenant?->locations()->orderByDesc('is_default')->value('region');

        return is_string($region) && strtoupper($region) === 'QC' ? 'QC' : 'ON';
    }

    private static function validDate(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
