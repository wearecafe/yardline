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
> & { contract_price: boolean; on_hand: string | null };
