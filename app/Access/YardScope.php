<?php

namespace App\Access;

use App\Models\Location;
use App\Models\Order;
use App\Models\Rental;
use App\Models\Shipment;
use App\Models\Truck;
use App\Models\User;
use App\Tenancy\TenantRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Exists;

/**
 * Keeps people to the yards they work. Owner and Admin work every yard;
 * everyone else sees and changes orders, stock, trucks, stops and rental
 * units only at their assigned yards (Settings → Users).
 *
 * Lists filter their queries with apply(); single records are guarded by
 * the RestrictByYard middleware through HasYard.
 */
final class YardScope
{
    /**
     * The yards someone works, or null for every yard.
     *
     * @return list<int>|null
     */
    public static function yards(?User $user): ?array
    {
        return $user?->yardIds();
    }

    /**
     * Narrow a query to the user's yards by a location column.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public static function apply(Builder $query, ?User $user, string $column = 'location_id'): Builder
    {
        $yards = self::yards($user);

        return $yards === null ? $query : $query->whereIn($column, $yards);
    }

    /**
     * The yards someone can pick from: in lists, filters and forms.
     *
     * @return Builder<Location>
     */
    public static function locations(?User $user): Builder
    {
        return self::apply(Location::query(), $user, 'locations.id');
    }

    /**
     * Validates a location id from a request: one of the tenant's yards,
     * and one the user works.
     */
    public static function locationRule(?User $user): Exists
    {
        return self::existsIn('locations', $user, 'id');
    }

    /**
     * Validates an id from a request for a row that belongs to a yard (a
     * rental unit, say): the tenant's, at a yard the user works.
     */
    public static function existsIn(string $table, ?User $user, string $column = 'location_id'): Exists
    {
        $rule = TenantRule::exists($table);
        $yards = self::yards($user);

        return $yards === null ? $rule : $rule->whereIn($column, $yards === [] ? [0] : $yards);
    }

    /**
     * Their default yard: the business's default if they work it,
     * otherwise the first of theirs.
     */
    public static function defaultYard(?User $user): ?Location
    {
        return self::locations($user)->orderByDesc('is_default')->orderBy('id')->first();
    }

    /**
     * Trucks based at their yards, and trucks based nowhere in particular.
     *
     * @param  Builder<Truck>  $query
     * @return Builder<Truck>
     */
    public static function trucks(Builder $query, ?User $user): Builder
    {
        $yards = self::yards($user);

        return $yards === null ? $query : $query->where(fn ($q) => $q->whereNull('location_id')->orWhereIn('location_id', $yards));
    }

    /**
     * Stops for their yards' orders and rentals, stops on their yards'
     * trucks, and pallet pickups no truck has taken yet.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query  a Stop query
     * @return Builder<T>
     */
    public static function stops(Builder $query, ?User $user): Builder
    {
        $yards = self::yards($user);

        if ($yards === null) {
            return $query;
        }

        $trucks = self::trucks(Truck::query(), $user)->whereNotNull('location_id')->select('id');

        return $query->where(fn ($q) => $q
            ->whereHasMorph('stoppable', [Order::class, Rental::class], fn ($s) => $s->whereIn('location_id', $yards))
            ->orWhereHasMorph('stoppable', [Shipment::class], fn ($s) => $s->whereHas('order', fn ($o) => $o->whereIn('location_id', $yards)))
            ->orWhereIn('truck_id', $trucks)
            ->orWhere(fn ($p) => $p->where('stoppable_type', 'organization')->whereNull('truck_id')));
    }
}
