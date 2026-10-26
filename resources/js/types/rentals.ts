export type RentalStatus =
    | 'quote'
    | 'reserved'
    | 'out'
    | 'overdue'
    | 'returned'
    | 'cancelled';

export type RentalSummary = {
    id: number;
    number: string;
    status: RentalStatus;
    customer: string;
    equipment: string;
    starts_at: string;
    ends_at: string;
    total_cents: number;
    balance_cents: number;
    signed: boolean;
    source: string;
};

export type RentalCustomer = {
    id: number;
    organization_id: number | null;
    organization?: string | null;
    name: string;
    email: string | null;
    phone: string | null;
    card: string | null;
    licence: { id: number; expires_on: string | null } | null;
    insurance_until: string | null;
};

export type RentalItemDetail = {
    id: number;
    product_id: number;
    rental_unit_id: number | null;
    description: string;
    serial: string | null;
    rate_summary: string | null;
    charge_cents: number;
    day_rate_equivalent_cents: number;
    deposit_cents: number;
    hours_out: string | null;
    hours_in: string | null;
    hour_meter: string | null;
    fuel_charge_cents: number;
    cleaning_fee_cents: number;
};

export type RentalPaymentRow = {
    id: number;
    kind:
        | 'charge'
        | 'deposit_hold'
        | 'deposit_release'
        | 'deposit_capture'
        | 'refund';
    tender: string;
    status: 'pending' | 'succeeded' | 'failed' | 'cancelled';
    amount_cents: number;
    reference: string | null;
    checkout_url: string | null;
    succeeded_at: string | null;
    created_at: string;
    recorded_by: string | null;
};

export type InspectionRecord = {
    id: number;
    kind: 'check_out' | 'return';
    serial: string;
    hour_meter: string | null;
    fuel: 'full' | 'half' | 'low' | null;
    clean: boolean;
    notes: string | null;
    created_at: string | null;
    photos: {
        id: number;
        url: string;
        label: string | null;
        new_damage: boolean;
    }[];
};

export type RentalDetail = {
    id: number;
    number: string;
    status: RentalStatus;
    editable: boolean;
    source: string;
    organization_id: number | null;
    customer_id: number | null;
    location_id: number;
    starts_at: string;
    ends_at: string;
    checked_out_at: string | null;
    returned_at: string | null;
    fulfillment: 'pickup' | 'delivery';
    delivery_address: {
        line1?: string | null;
        city?: string | null;
        region?: string | null;
        postal_code?: string | null;
    } | null;
    delivery_zone_id: number | null;
    damage_waiver: boolean;
    damage_waiver_percent: string;
    bill_fuel_cleaning: boolean;
    text_reminders: boolean;
    rental_cents: number;
    waiver_cents: number;
    delivery_fee_cents: number;
    charges_cents: number;
    subtotal_cents: number;
    tax_cents: number;
    total_cents: number;
    amount_paid_cents: number;
    balance_cents: number;
    deposit_cents: number;
    deposit_status: 'none' | 'held' | 'released' | 'captured';
    deposit_captured_cents: number;
    payment_method: 'card_on_file' | 'pay_now' | 'payment_link' | 'counter';
    notes: string | null;
    customer: RentalCustomer | null;
    organization: { id: number; name: string; tax_exempt: boolean } | null;
    location: { id: number; name: string };
    delivery_zone: { id: number; name: string; fee_cents: number } | null;
    items: RentalItemDetail[];
    charges: {
        id: number;
        kind: string;
        description: string;
        amount_cents: number;
        covered_by_waiver: boolean;
    }[];
    payments: RentalPaymentRow[];
    agreement: {
        signed_at: string | null;
        signer_name: string | null;
        signed_via: string | null;
        pdf_url: string | null;
    } | null;
    inspections: InspectionRecord[];
    signing_url: string | null;
};

export type RentalEstimate = {
    valid: boolean;
    available?: boolean;
    problems: string[];
    notes?: string[];
    lines?: {
        product_id: number;
        name: string;
        rental_unit_id: number | null;
        serial: string | null;
        rate_summary: string;
        billable_days: number;
        charge_cents: number;
        day_rate_cents: number;
        day_rate_equivalent_cents: number;
        deposit_cents: number;
    }[];
    rental_cents?: number;
    waiver_percent?: number;
    waiver_cents?: number;
    delivery_zone?: { id: number; name: string } | null;
    delivery_fee_cents?: number;
    subtotal_cents?: number;
    tax_cents?: number;
    tax_note?: string | null;
    total_cents?: number;
    deposit_cents?: number;
};

export type RentalProductOption = {
    id: number;
    name: string;
    category: string | null;
    day_cents: number;
    week_cents: number | null;
    deposit_cents: number;
    units: {
        id: number;
        serial: string;
        status: 'available' | 'in_shop' | 'retired';
        service_due: boolean;
    }[];
};
