<?php

namespace App\Enums;

/**
 * Job-based roles for the people who sign in to a yard. A user can hold
 * several; their access is the union of their roles, plus any permissions
 * granted or removed on them (see Permission). Owner and Admin work every
 * yard; every other role works only the yards it's assigned.
 *
 * Before roles were split by job, 'staff' covered sales and 'picker' was
 * the order picker. Those values are read as Sales and Yard crew.
 */
enum UserRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';

    /** Counter and inside sales: the counter, quotes, orders and payments. */
    case Sales = 'sales';

    /** Runs rentals: bookings, agreements, deposits and rental charges. */
    case RentalDesk = 'rental_desk';

    /** Picks and loads, receives counts and inspects rentals. Never sees prices. */
    case YardCrew = 'yard_crew';

    /** Plans deliveries on the dispatch board. Sees delivery fees only. */
    case Dispatcher = 'dispatcher';

    /** Signs in to their own run of stops and nothing else. */
    case Driver = 'driver';

    /** Purchase orders, receiving, counts and the product editor (not selling prices). */
    case Purchasing = 'purchasing';

    /** Invoices, statements, credit limits, refunds and QuickBooks sync errors. */
    case Accounts = 'accounts';

    /** Fleet maintenance: service schedules, repairs and hour meters. */
    case ServiceTech = 'service_tech';

    /**
     * A role value, including the ones used before roles were split by job.
     */
    public static function fromValue(?string $value): ?self
    {
        return match ($value) {
            'staff' => self::Sales,
            'picker' => self::YardCrew,
            null => null,
            default => self::tryFrom($value),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Sales => 'Sales',
            self::RentalDesk => 'Rental desk',
            self::YardCrew => 'Yard crew',
            self::Dispatcher => 'Dispatcher',
            self::Driver => 'Driver',
            self::Purchasing => 'Purchasing & inventory',
            self::Accounts => 'Accounts',
            self::ServiceTech => 'Service tech',
        };
    }

    /** The job, in a few words, for the users and roles screens. */
    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Runs the business. Sees and changes everything.',
            self::Admin => 'Day-to-day manager: settings, price lists, users and reports.',
            self::Sales => 'Counter sales, quotes, orders and taking payment.',
            self::RentalDesk => 'Rentals, agreements, deposits and rental charges.',
            self::YardCrew => 'Picks and loads orders, counts pallets and inspects rentals. No prices.',
            self::Dispatcher => 'Plans deliveries, trucks and drivers. Sees delivery fees only.',
            self::Driver => 'Their own run of stops and proof of delivery.',
            self::Purchasing => 'Purchase orders, receiving, stock counts and products (not selling prices).',
            self::Accounts => 'Invoices, statements, credit limits, refunds and QuickBooks sync errors.',
            self::ServiceTech => 'Fleet maintenance, repairs and hour meters.',
        };
    }

    /**
     * Owner and Admin run the business: settings, price lists, credit
     * settings and every screen.
     */
    public function canManageBusiness(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }

    /** Every role except Owner and Admin works only its assigned yards. */
    public function isYardScoped(): bool
    {
        return ! $this->canManageBusiness();
    }

    /**
     * Roles that work on a shared yard tablet or truck device, and switch
     * users there with their time-clock PIN.
     */
    public function switchesWithPin(): bool
    {
        return in_array($this, [self::YardCrew, self::Driver, self::ServiceTech], true);
    }

    /** Whether this role sees selling prices, totals and balances. */
    public function seesPrices(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Sales, self::RentalDesk, self::Purchasing, self::Accounts], true);
    }

    /**
     * Roles anyone with Manage users and roles can give. There's one owner
     * per business, set when it's created.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $role) => $role !== self::Owner));
    }

    /**
     * Roles a business can let sign off a quality check.
     *
     * @return list<self>
     */
    public static function checkers(): array
    {
        return [self::Owner, self::Admin, self::Sales, self::Dispatcher, self::YardCrew];
    }

    /**
     * The order roles are listed in, and how a user's main role is chosen
     * when they hold several.
     */
    public function rank(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}
