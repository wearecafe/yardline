<?php

namespace App\Imports;

use App\Models\Import;
use App\Models\Location;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\User;

/**
 * What an import run knows while it goes: who's importing, the yards,
 * products, price lists and accounts it has looked up, and, in a preview,
 * what earlier rows will create, so later rows can refer to them.
 */
final class ImportContext
{
    /** @var array<string, array<string, true>> */
    private array $planned = [];

    /** @var array<string, Product> */
    private array $products = [];

    /** @var array<string, Organization> */
    private array $organizations = [];

    /** @var array<string, PriceList> */
    private array $priceLists = [];

    /** @var array<string, Location>|null */
    private ?array $yards = null;

    public function __construct(
        public readonly Import $import,
        public readonly ?User $user,
        /** A preview: check only, nothing is saved. */
        public readonly bool $preview,
    ) {}

    /** Remember that an earlier row in a preview will create this. */
    public function plan(string $type, string $key): void
    {
        $this->planned[$type][self::key($key)] = true;
    }

    public function planned(string $type, string $key): bool
    {
        return isset($this->planned[$type][self::key($key)]);
    }

    public function product(string $sku): ?Product
    {
        $key = self::key($sku);

        if (isset($this->products[$key])) {
            return $this->products[$key];
        }

        $product = Product::query()->whereRaw('lower(sku) = ?', [$key])->first();

        return $product === null ? null : $this->products[$key] = $product;
    }

    public function priceList(string $name): ?PriceList
    {
        $key = self::key($name);

        if (isset($this->priceLists[$key])) {
            return $this->priceLists[$key];
        }

        $list = PriceList::query()->whereRaw('lower(name) = ?', [$key])->first();

        return $list === null ? null : $this->priceLists[$key] = $list;
    }

    /**
     * An account by its account number, or failing that its name.
     */
    public function organization(string $numberOrName): ?Organization
    {
        $key = self::key($numberOrName);

        if (isset($this->organizations[$key])) {
            return $this->organizations[$key];
        }

        $org = Organization::query()->whereRaw('lower(account_number) = ?', [$key])->first()
            ?? Organization::query()->whereRaw('lower(name) = ?', [$key])->first();

        return $org === null ? null : $this->organizations[$key] = $org;
    }

    /** A yard by its name or its code. */
    public function yard(string $nameOrCode): ?Location
    {
        if ($this->yards === null) {
            $this->yards = [];

            foreach (Location::query()->get() as $yard) {
                $this->yards[self::key($yard->name)] = $yard;
                $this->yards[self::key((string) $yard->code)] = $yard;
            }
        }

        return $this->yards[self::key($nameOrCode)] ?? null;
    }

    public function defaultYard(): ?Location
    {
        return Location::query()->orderByDesc('is_default')->orderBy('id')->first();
    }

    public static function key(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
