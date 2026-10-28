<?php

namespace App\Imports;

use App\Imports\Importers\BalancesImporter;
use App\Imports\Importers\JobSitesImporter;
use App\Imports\Importers\OrganizationsImporter;
use App\Imports\Importers\PriceListsImporter;
use App\Imports\Importers\ProductsImporter;
use App\Imports\Importers\StockImporter;

/**
 * What a spreadsheet loads, in the order a yard loads them: each one can
 * refer to what the ones before it brought in.
 */
enum ImportKind: string
{
    case Products = 'products';
    case PriceLists = 'price_lists';
    case Organizations = 'organizations';
    case JobSites = 'job_sites';
    case Stock = 'stock';
    case Balances = 'balances';

    public function label(): string
    {
        return match ($this) {
            self::Products => 'Products and units',
            self::PriceLists => 'Price lists',
            self::Organizations => 'Accounts and contacts',
            self::JobSites => 'Job sites',
            self::Stock => 'Opening stock',
            self::Balances => 'Opening balances',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Products => 'Everything you sell, one row per product, plus a row for each other unit it sells in (pallet, layer, tonne).',
            self::PriceLists => 'Contractor and builder prices: a price per product and unit, volume breaks, or a discount off a whole category.',
            self::Organizations => 'Your contractor accounts with terms, credit limits and price lists, and the people who buy for them.',
            self::JobSites => 'Where each account takes deliveries.',
            self::Stock => 'What’s on hand at each yard today, with its cost.',
            self::Balances => 'What each account owes from your old system, invoice by invoice, so aging and statements carry on.',
        };
    }

    /** What has to be in YardRunner first. */
    public function needs(): ?string
    {
        return match ($this) {
            self::Products, self::Organizations => null,
            self::PriceLists, self::Stock => 'Import products first.',
            self::JobSites, self::Balances => 'Import accounts first.',
        };
    }

    public function importer(): Importer
    {
        return app(match ($this) {
            self::Products => ProductsImporter::class,
            self::PriceLists => PriceListsImporter::class,
            self::Organizations => OrganizationsImporter::class,
            self::JobSites => JobSitesImporter::class,
            self::Stock => StockImporter::class,
            self::Balances => BalancesImporter::class,
        });
    }

    /**
     * @return list<Column>
     */
    public function columns(): array
    {
        return match ($this) {
            self::Products => [
                new Column('sku', 'Your product code. Rows with the same SKU are the same product.', true, ['code', 'item', 'item_code', 'product_code']),
                new Column('name', 'Required on the product’s first row.', false, ['product', 'product_name', 'description_short']),
                new Column('unit', 'Blank on the product’s own row. On the other rows, the unit it also sells in: Pallet, Layer, Tonne.', false, ['selling_unit']),
                new Column('base_unit', 'What stock is counted in: each, bag, pallet, cube, bundle, tonne, cu yd, sq ft or lin ft. Required for new products.', false, ['stock_unit', 'uom']),
                new Column('per_base', 'On unit rows: how many base units one of it is (a pallet of 56 bags is 56).', false, ['factor', 'contains', 'quantity_per_unit']),
                new Column('price', 'Selling price for that unit, before tax.', true, ['retail_price', 'list_price', 'sell_price']),
                new Column('category', 'Groups products for reports and category discounts.', false, ['group', 'department']),
                new Column('cost', 'What it costs you, per base unit.', false, ['unit_cost', 'average_cost']),
                new Column('taxable', 'yes or no. Blank is yes.'),
                new Column('tax_code', 'A Stripe Tax code if it’s taxed differently from general goods.'),
                new Column('track_inventory', 'yes or no. Blank is yes.', false, ['stocked']),
                new Column('per_pallet', 'Base units on one pallet, for pallet deposits and fees.'),
                new Column('weight_kg', 'Weight of one base unit, for truck loads.', false, ['weight']),
                new Column('pallet_deposit', 'yes or no: charges the pallet deposit.'),
                new Column('pallet_usage_fee', 'yes or no: charges the pallet usage fee.'),
                new Column('price_list_exempt', 'yes or no: always sells at its own price.'),
                new Column('sellable', 'On unit rows: no if it isn’t sold on its own. Blank is yes.'),
                new Column('active', 'no to load it switched off. Blank is yes.'),
                new Column('description', 'Longer description.'),
            ],
            self::PriceLists => [
                new Column('price_list', 'The price list’s name, e.g. Contractor. New names make a new list.', true, ['list', 'price_list_name', 'tier']),
                new Column('sku', 'A product’s SKU, for a price on one product. Use either this or category.', false, ['code', 'item']),
                new Column('unit', 'Which of the product’s units the price is for. Blank is its base unit.'),
                new Column('price', 'With sku: the price for that unit, before tax.', false, ['list_price']),
                new Column('min_quantity', 'With sku: the quantity the price starts at, for volume breaks. Blank is 1.', false, ['break', 'min_qty']),
                new Column('category', 'A product category, for a discount off the whole category. Use either this or sku.'),
                new Column('discount_percent', 'With category: percent off, e.g. 10.', false, ['percent_off']),
                new Column('discount_amount', 'With category: dollars off each base unit, e.g. 0.50.', false, ['amount_off']),
                new Column('starts_on', 'Optional first day, like 2026-11-01.'),
                new Column('ends_on', 'Optional last day.'),
            ],
            self::Organizations => [
                new Column('name', 'The account’s name. Rows with the same account number (or name) are the same account.', true, ['account_name', 'company', 'customer']),
                new Column('account_number', 'Your account number for them.', false, ['account', 'account_no', 'customer_number']),
                new Column('email', 'Where statements and invoices go.', false, ['ap_email', 'billing_email']),
                new Column('phone', ''),
                new Column('address_line1', '', false, ['address', 'street']),
                new Column('address_line2', ''),
                new Column('city', ''),
                new Column('region', 'Province, like ON or QC.', false, ['province', 'state']),
                new Column('postal_code', '', false, ['postal', 'zip']),
                new Column('terms', 'due on receipt, net 15, net 30 or net 60.', false, ['payment_terms']),
                new Column('credit_limit', 'Dollars. Blank or 0 is no credit.'),
                new Column('price_list', 'The name of a price list already in YardRunner.', false, ['tier']),
                new Column('tax_exempt', 'yes or no.'),
                new Column('tax_exempt_number', ''),
                new Column('notes', ''),
                new Column('contact_first_name', 'Someone who buys for the account.', false, ['contact', 'first_name']),
                new Column('contact_last_name', '', false, ['last_name']),
                new Column('contact_email', 'Their own email, for the contractor portal.'),
                new Column('contact_phone', ''),
                new Column('contact_role', 'buyer, approver or admin. Blank is buyer.'),
                new Column('can_charge', 'yes or no: can charge to the account. Blank is yes.'),
            ],
            self::JobSites => [
                new Column('account', 'The account’s number or name.', true, ['account_number', 'organization', 'customer']),
                new Column('name', 'What you call the site, e.g. Maple Ridge phase 2.', true, ['site', 'site_name', 'job']),
                new Column('address_line1', '', true, ['address', 'street']),
                new Column('address_line2', ''),
                new Column('city', ''),
                new Column('region', 'Province, like ON.', false, ['province']),
                new Column('postal_code', 'Needed to work out delivery and tax.', false, ['postal']),
                new Column('yard', 'The yard that usually delivers there, by name or code.'),
                new Column('po_required', 'yes or no: orders need a PO number.'),
                new Column('delivery_notes', '', false, ['notes']),
                new Column('default', 'yes for the account’s main site.'),
            ],
            self::Stock => [
                new Column('sku', 'A product’s SKU.', true, ['code', 'item']),
                new Column('yard', 'The yard’s name or code.', true, ['location', 'warehouse']),
                new Column('quantity', 'On hand now, in the product’s base unit. Sets the count; it doesn’t add to it.', true, ['on_hand', 'qty']),
                new Column('unit_cost', 'Cost per base unit, for the profit report. Blank uses the product’s cost.', false, ['cost']),
                new Column('lot', 'Colour run, for products that track them.', false, ['colour_run', 'color_run', 'batch']),
                new Column('bin', 'Where it is in the yard.', false, ['location_in_yard']),
                new Column('reorder_point', 'Flag it as low at or below this.', false, ['min', 'reorder']),
            ],
            self::Balances => [
                new Column('account', 'The account’s number or name.', true, ['account_number', 'customer']),
                new Column('invoice_number', 'The invoice number from your old system. Loading the same one again is skipped.', true, ['invoice', 'reference', 'document']),
                new Column('issued_on', 'The invoice date, like 2026-09-15.', true, ['date', 'invoice_date']),
                new Column('amount', 'What’s still owing on it. A negative amount is a credit on the account.', true, ['balance', 'open_amount', 'owing']),
                new Column('due_on', 'Blank works it out from the account’s terms.', false, ['due', 'due_date']),
                new Column('po_number', '', false, ['po']),
                new Column('yard', 'Blank is your main yard.'),
            ],
        };
    }

    /**
     * Worked rows for the sample.
     *
     * @return list<array<string, string>>
     */
    public function examples(): array
    {
        return match ($this) {
            self::Products => [
                ['sku' => 'POLY-30', 'name' => 'Polymeric sand 30 kg', 'base_unit' => 'bag', 'price' => '34.99', 'category' => 'Joint sand', 'cost' => '21.50', 'per_pallet' => '56', 'weight_kg' => '30', 'pallet_deposit' => 'yes'],
                ['sku' => 'POLY-30', 'unit' => 'Pallet', 'per_base' => '56', 'price' => '1799.00'],
                ['sku' => 'HOLL-60', 'name' => 'Holland paver 60 mm, charcoal', 'base_unit' => 'sq ft', 'price' => '4.25', 'category' => 'Pavers', 'cost' => '2.60', 'per_pallet' => '115', 'pallet_deposit' => 'yes', 'pallet_usage_fee' => 'yes'],
                ['sku' => 'HOLL-60', 'unit' => 'Layer', 'per_base' => '11.5', 'price' => '46.00'],
                ['sku' => 'HOLL-60', 'unit' => 'Pallet', 'per_base' => '115', 'price' => '449.00'],
                ['sku' => 'SCR-A', 'name' => 'Screenings', 'base_unit' => 'tonne', 'price' => '42.00', 'category' => 'Aggregate', 'cost' => '18.00'],
                ['sku' => 'SCR-A', 'unit' => 'Cubic yard', 'per_base' => '1.35', 'price' => '55.00'],
                ['sku' => 'MULCH-BLK', 'name' => 'Black mulch', 'base_unit' => 'cu yd', 'price' => '49.00', 'category' => 'Mulch', 'cost' => '22.00'],
                ['sku' => 'EDGE-8', 'name' => 'Plastic edging 8 ft', 'base_unit' => 'each', 'price' => '12.99', 'category' => 'Edging', 'track_inventory' => 'yes'],
                ['sku' => 'DELIVERY-LOCAL', 'name' => 'Local delivery', 'base_unit' => 'each', 'price' => '95.00', 'track_inventory' => 'no', 'price_list_exempt' => 'yes'],
            ],
            self::PriceLists => [
                ['price_list' => 'Contractor', 'sku' => 'POLY-30', 'price' => '31.50'],
                ['price_list' => 'Contractor', 'sku' => 'POLY-30', 'unit' => 'Pallet', 'price' => '1650.00'],
                ['price_list' => 'Contractor', 'sku' => 'HOLL-60', 'price' => '3.90'],
                ['price_list' => 'Contractor', 'sku' => 'SCR-A', 'price' => '38.00'],
                ['price_list' => 'Contractor', 'sku' => 'SCR-A', 'price' => '35.00', 'min_quantity' => '20'],
                ['price_list' => 'Builder', 'category' => 'Aggregate', 'discount_percent' => '10'],
                ['price_list' => 'Builder', 'category' => 'Pavers', 'discount_amount' => '0.25', 'starts_on' => '2026-11-01', 'ends_on' => '2027-03-31'],
            ],
            self::Organizations => [
                ['name' => 'Brantwood Landscaping', 'account_number' => 'C-10021', 'email' => 'ap@brantwood.example', 'phone' => '905-555-0100', 'address_line1' => '220 Brant St', 'city' => 'Hamilton', 'region' => 'ON', 'postal_code' => 'L8L 4C1', 'terms' => 'net 30', 'credit_limit' => '25000', 'price_list' => 'Contractor', 'contact_first_name' => 'Maria', 'contact_last_name' => 'Rossi', 'contact_email' => 'maria@brantwood.example', 'contact_phone' => '905-555-0101', 'contact_role' => 'admin'],
                ['name' => 'Brantwood Landscaping', 'account_number' => 'C-10021', 'contact_first_name' => 'Dev', 'contact_last_name' => 'Patel', 'contact_phone' => '905-555-0142', 'contact_role' => 'buyer', 'can_charge' => 'yes'],
                ['name' => 'Ridgeline Homes', 'account_number' => 'C-10044', 'email' => 'payables@ridgeline.example', 'city' => 'Burlington', 'region' => 'ON', 'terms' => 'net 15', 'credit_limit' => '50000', 'price_list' => 'Builder', 'tax_exempt' => 'no', 'contact_first_name' => 'Sam', 'contact_last_name' => 'Okafor', 'contact_email' => 'sam@ridgeline.example', 'contact_role' => 'approver'],
                ['name' => 'Dundas Valley Gardens', 'account_number' => 'C-10052', 'email' => 'office@dvgardens.example', 'region' => 'ON', 'terms' => 'due on receipt', 'notes' => 'Cash and card only'],
            ],
            self::JobSites => [
                ['account' => 'C-10021', 'name' => 'Maple Ridge phase 2', 'address_line1' => '48 Orchard Cres', 'city' => 'Waterdown', 'region' => 'ON', 'postal_code' => 'L8B 0C2', 'po_required' => 'yes', 'delivery_notes' => 'Drop at the back lot, gate code 4471', 'default' => 'yes'],
                ['account' => 'C-10021', 'name' => 'Brantwood yard', 'address_line1' => '220 Brant St', 'city' => 'Hamilton', 'region' => 'ON', 'postal_code' => 'L8L 4C1'],
                ['account' => 'C-10044', 'name' => 'Lakeshore townhomes', 'address_line1' => '1200 Lakeshore Rd', 'city' => 'Burlington', 'region' => 'ON', 'postal_code' => 'L7S 1A1', 'po_required' => 'yes'],
            ],
            self::Stock => [
                ['sku' => 'POLY-30', 'yard' => 'Main yard', 'quantity' => '420', 'unit_cost' => '21.50', 'bin' => 'Row C', 'reorder_point' => '112'],
                ['sku' => 'HOLL-60', 'yard' => 'Main yard', 'quantity' => '2300', 'unit_cost' => '2.60', 'bin' => 'Paver lot 2', 'reorder_point' => '575'],
                ['sku' => 'SCR-A', 'yard' => 'Main yard', 'quantity' => '180.5', 'unit_cost' => '18.00', 'bin' => 'Bay 1', 'reorder_point' => '40'],
                ['sku' => 'MULCH-BLK', 'yard' => 'Main yard', 'quantity' => '60', 'bin' => 'Bay 4'],
                ['sku' => 'EDGE-8', 'yard' => 'Main yard', 'quantity' => '85'],
            ],
            self::Balances => [
                ['account' => 'C-10021', 'invoice_number' => '48812', 'issued_on' => '2026-09-15', 'amount' => '4812.40', 'po_number' => 'MR2-118'],
                ['account' => 'C-10021', 'invoice_number' => '49030', 'issued_on' => '2026-10-06', 'amount' => '1260.75', 'due_on' => '2026-11-05'],
                ['account' => 'C-10021', 'invoice_number' => 'CM-311', 'issued_on' => '2026-10-02', 'amount' => '-120.00'],
                ['account' => 'C-10044', 'invoice_number' => '48977', 'issued_on' => '2026-09-30', 'amount' => '18450.00', 'yard' => 'Main yard'],
            ],
        };
    }

    /** The blank template: just the headings, ready to fill in. */
    public function template(): string
    {
        return $this->csv([]);
    }

    /**
     * The sample: the headings and worked rows. The six samples go
     * together (the same products, accounts and yard), so they can be
     * loaded in order to try it out.
     */
    public function sample(): string
    {
        return $this->csv($this->examples());
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function csv(array $rows): string
    {
        $keys = array_map(fn (Column $c) => $c->key, $this->columns());
        $out = fopen('php://temp', 'r+');

        fputcsv($out, $keys, escape: '');

        foreach ($rows as $row) {
            fputcsv($out, array_map(fn (string $key) => $row[$key] ?? '', $keys), escape: '');
        }

        rewind($out);

        return (string) stream_get_contents($out);
    }
}
