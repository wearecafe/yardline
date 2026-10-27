export type Option = { value: string; label: string };

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
};

export type ProductKind = 'sale' | 'rental';

export type Product = {
    id: number;
    sku: string;
    name: string;
    description: string | null;
    category: string | null;
    kind: ProductKind;
    unit: string;
    price_cents: number;
    cost_cents: number | null;
    rental_period: 'day' | 'week' | null;
    deposit_cents: number;
    tax_code: string | null;
    track_inventory: boolean;
    is_active: boolean;
    on_hand?: string | null;
    rate_hour_cents?: number | null;
    rate_half_day_cents?: number | null;
    rate_weekend_cents?: number | null;
    rate_week_cents?: number | null;
    rate_month_cents?: number | null;
    minimum_charge_cents?: number;
    fuel_charge_cents?: number;
    cleaning_fee_cents?: number;
    service_interval_hours?: number | null;
    service_interval_days?: number | null;
    use_case?: string | null;
    bookable_online?: boolean;
    taxable?: boolean;
    weight_kg?: string | null;
    supplier_name?: string | null;
    pallet_deposit?: boolean;
    supplier_id?: number | null;
    track_lots?: boolean;
    per_pallet?: string | null;
    previous_cost_cents?: number | null;
    cost_changed_at?: string | null;
};

export type PriceListSummary = {
    id: number;
    name: string;
    description: string | null;
    items_count: number;
    organizations_count: number;
};

export type PriceListItem = {
    id: number;
    product_id: number;
    min_quantity: string;
    price_cents: number;
    product_unit_id: number | null;
    starts_on: string | null;
    ends_on: string | null;
    unit: { id: number; name: string; price_cents: number } | null;
    product: Pick<Product, 'id' | 'sku' | 'name' | 'unit' | 'price_cents'>;
};

export type Organization = {
    id: number;
    name: string;
    account_number: string;
    email: string | null;
    phone: string | null;
    address_line1: string | null;
    address_line2: string | null;
    city: string | null;
    region: string | null;
    postal_code: string | null;
    country: string;
    price_list_id: number | null;
    price_list?: { id: number; name: string } | null;
    credit_limit_cents: number;
    payment_terms: string;
    credit_hold: boolean;
    tax_exempt: boolean;
    tax_exempt_number: string | null;
    notes: string | null;
    customers?: Customer[];
};

export type Customer = {
    id: number;
    organization_id: number | null;
    first_name: string;
    last_name: string | null;
    full_name: string;
    email: string | null;
    phone: string | null;
    notes: string | null;
    organization?: { id: number; name: string } | null;
};

export type Location = {
    id: number;
    name: string;
    code: string;
    address_line1: string | null;
    address_line2: string | null;
    city: string | null;
    region: string | null;
    postal_code: string | null;
    country: string;
    is_default: boolean;
};

export type OrderType = 'sale' | 'rental';
export type OrderStatus =
    | 'draft'
    | 'confirmed'
    | 'fulfilled'
    | 'returned'
    | 'cancelled';
export type PaymentStatus = 'unpaid' | 'partial' | 'paid';
export type Settlement = 'account' | 'prepaid';

export type OrderSummary = {
    id: number;
    number: string;
    type: OrderType;
    status: OrderStatus;
    is_quote?: boolean;
    settlement: Settlement | null;
    payment_status: PaymentStatus;
    organization: string | null;
    customer: string | null;
    total_cents: number;
    balance_cents: number;
    requested_on: string | null;
    created_at: string;
};

export type OrderLine = {
    id: number;
    product_id: number | null;
    sku: string | null;
    description: string;
    quantity: string;
    unit: string;
    unit_price_cents: number;
    periods: number;
    period_unit: 'day' | 'week' | null;
    price_overridden: boolean;
    price_source?: 'list' | 'price_list' | 'contract' | 'manual' | null;
    product_unit_id?: number | null;
    base_quantity?: string | null;
    shipped_quantity?: string;
    returned_quantity?: string;
    pallets?: number;
    line_total_cents: number;
    tax_cents: number;
    deposit_cents: number;
};

export type Payment = {
    id: number;
    tender: string;
    status: 'pending' | 'succeeded' | 'failed';
    amount_cents: number;
    reference: string | null;
    checkout_url: string | null;
    succeeded_at: string | null;
    created_at: string;
};

export type DeliveryAddress = {
    line1?: string | null;
    city?: string | null;
    region?: string | null;
    postal_code?: string | null;
    country?: string | null;
};

export type Order = {
    id: number;
    number: string;
    type: OrderType;
    status: OrderStatus;
    source: string;
    organization_id: number | null;
    customer_id: number | null;
    organization_location_id: number | null;
    location_id: number;
    fulfillment: 'pickup' | 'delivery';
    delivery_address: DeliveryAddress | null;
    requested_on: string | null;
    po_number: string | null;
    settlement: Settlement | null;
    payment_status: PaymentStatus;
    credit_override_reason: string | null;
    credit_override_user?: { id: number; name: string } | null;
    subtotal_cents: number;
    tax_cents: number;
    total_cents: number;
    deposit_cents: number;
    amount_paid_cents: number;
    amount_due_cents: number;
    balance_cents: number;
    rental_starts_at: string | null;
    rental_ends_at: string | null;
    confirmed_at: string | null;
    fulfilled_at: string | null;
    returned_at: string | null;
    notes: string | null;
    created_at: string;
    quoted_at: string | null;
    quote_expires_on: string | null;
    revision: number;
    quote_accepted_at: string | null;
    quote_accepted_by: string | null;
    organization_location?: {
        id: number;
        name: string;
        po_required: boolean;
    } | null;
    fulfillment_status: string | null;
    delivery_fee_cents: number;
    delivery_fee_manual: boolean;
    delivery_km: string | null;
    delivery_loads: number | null;
    load_weight_kg: string | null;
    needs_boom: boolean;
    delivery_zone?: { id: number; name: string } | null;
    invoice?: {
        id: number;
        number: string;
        status: string;
        due_on: string;
    } | null;
    credit_notes?: {
        id: number;
        number: string;
        total_cents: number;
        issued_on: string;
    }[];
    shipments?: {
        id: number;
        number: string;
        method: 'pickup' | 'delivery';
        status: string;
        created_at: string;
        loaded_at: string | null;
        delivered_at: string | null;
        pod_name: string | null;
        weight_kg: string | null;
        picker: { id: number; name: string } | null;
        lines: {
            id: number;
            quantity: string;
            picked_quantity: string | null;
            short: boolean;
        }[];
        stop: {
            id: number;
            status: string;
            date: string | null;
            starts_at: string | null;
            truck: { id: number; name: string } | null;
        } | null;
    }[];
    revisions?: {
        id: number;
        revision: number;
        created_at: string;
        snapshot: { total_cents: number; expires_on: string | null };
        creator: { id: number; name: string } | null;
    }[];
    lines: OrderLine[];
    payments: Payment[];
    organization: Organization | null;
    customer: Customer | null;
    location: Location;
};

export type CreditAssessment = {
    outcome: string;
    approved: boolean;
    overridable: boolean;
    message: string;
    limit_cents: number;
    used_cents: number;
    available_cents: number;
    required_cents: number;
};

export type OrderProduct = Pick<
    Product,
    | 'id'
    | 'sku'
    | 'name'
    | 'unit'
    | 'price_cents'
    | 'rental_period'
    | 'deposit_cents'
    | 'track_inventory'
> & {
    contract_price: boolean;
    on_hand: string | null;
    units: {
        id: number;
        name: string;
        is_base: boolean;
        sellable: boolean;
        factor: string;
        increment: string | null;
        min_quantity: string | null;
        price_cents: number;
        price_source: string;
    }[];
    default_unit_id: number | null;
    rounding: 'off' | 'up' | 'down';
};
