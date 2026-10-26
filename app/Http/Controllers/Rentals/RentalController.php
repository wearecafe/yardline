<?php

namespace App\Http\Controllers\Rentals;

use App\Enums\FulfillmentMethod;
use App\Enums\ProductKind;
use App\Enums\RentalPaymentMethod;
use App\Enums\RentalStatus;
use App\Enums\RentalTender;
use App\Http\Controllers\Controller;
use App\Http\Presenters\RentalPresenter;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalUnit;
use App\Rentals\CancelRental;
use App\Rentals\Notifications\RentalNotifier;
use App\Rentals\Payments\RentalPayments;
use App\Rentals\RentalEstimator;
use App\Rentals\RentalRules;
use App\Rentals\RentalSettings;
use App\Rentals\ReserveRental;
use App\Rentals\SaveRental;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRule;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rentals and contracts: the list, the New rental screen (also used to edit
 * quotes and reservations), and the contract view once equipment is out.
 */
class RentalController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();

        $rentals = Rental::query()
            ->with(['items.unit', 'organization:id,name', 'customer:id,first_name,last_name', 'agreement'])
            ->when($status === 'overdue', fn ($query) => $query
                ->where('status', RentalStatus::Out->value)
                ->where('ends_at', '<', now()))
            ->when($status !== '' && $status !== 'overdue', fn ($query) => $query->where('status', $status))
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where(fn ($query) => $query
                ->whereLike('number', "%{$search}%")
                ->orWhereHas('organization', fn ($query) => $query->whereLike('name', "%{$search}%"))
                ->orWhereHas('customer', fn ($query) => $query->whereLike('last_name', "%{$search}%")->orWhereLike('first_name', "%{$search}%"))))
            ->orderByRaw('case status when ? then 0 when ? then 1 when ? then 2 else 3 end', ['out', 'reserved', 'quote'])
            ->orderBy('starts_at')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Rental $rental) => RentalPresenter::summary($rental));

        return Inertia::render('rentals/index', [
            'rentals' => $rentals,
            'filters' => $request->only('status', 'search'),
        ]);
    }

    public function create(Request $request): Response
    {
        $tz = $this->timezone();
        $settings = RentalSettings::current();
        $start = $request->filled('starts_at')
            ? CarbonImmutable::parse($request->string('starts_at')->toString(), $tz)
            : CarbonImmutable::now($tz)->addDay()->setTimeFromTimeString($settings->openingTime);
        $end = $request->filled('ends_at')
            ? CarbonImmutable::parse($request->string('ends_at')->toString(), $tz)
            : $start->setTimeFromTimeString($settings->closingTime);

        return Inertia::render('rentals/edit', [
            'rental' => null,
            'defaults' => [
                'starts_at' => $start->format('Y-m-d\TH:i'),
                'ends_at' => $end->format('Y-m-d\TH:i'),
                'rental_unit_id' => $request->integer('rental_unit_id') ?: null,
                'product_id' => $request->integer('product_id') ?: null,
                'customer_id' => $request->integer('customer_id') ?: null,
            ],
            ...$this->formOptions(),
        ]);
    }

    public function store(Request $request, SaveRental $save): RedirectResponse
    {
        [$data, $items] = $this->validated($request);

        $rental = $save->handle($data, $items, $request->user());

        return to_route('rentals.show', $rental);
    }

    public function show(Rental $rental): Response
    {
        return Inertia::render('rentals/edit', [
            'rental' => RentalPresenter::detail($rental),
            'defaults' => null,
            ...$this->formOptions($rental),
            'tenders' => array_map(
                fn (RentalTender $tender) => ['value' => $tender->value, 'label' => $tender->label()],
                [RentalTender::CardTerminal, RentalTender::Cash, RentalTender::Cheque, RentalTender::ETransfer],
            ),
        ]);
    }

    public function update(Request $request, Rental $rental, SaveRental $save): RedirectResponse
    {
        [$data, $items] = $this->validated($request);

        $save->handle($data, $items, $request->user(), $rental);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$rental->number} saved."]);

        return back();
    }

    /**
     * Live summary for the New rental screen: availability, best rate,
     * waiver, delivery and tax, without saving anything.
     */
    public function preview(Request $request, RentalEstimator $estimator): JsonResponse
    {
        $data = $request->validate([
            'items' => ['array'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.rental_unit_id' => ['nullable', 'integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            'location_id' => ['nullable', 'integer'],
            'fulfillment' => ['nullable', 'string'],
            'delivery_address' => ['nullable', 'array'],
            'delivery_zone_id' => ['nullable', 'integer'],
            'damage_waiver' => ['nullable', 'boolean'],
            'organization_id' => ['nullable', 'integer'],
            'rental_id' => ['nullable', 'integer'],
        ]);

        $tz = $this->timezone();
        /** @var list<array{product_id: int, rental_unit_id?: int|null}> $items */
        $items = array_values($data['items'] ?? []);

        return response()->json($estimator->estimate(
            items: $items,
            start: CarbonImmutable::parse($data['starts_at'], $tz),
            end: CarbonImmutable::parse($data['ends_at'], $tz),
            locationId: isset($data['location_id']) ? (int) $data['location_id'] : null,
            delivery: ($data['fulfillment'] ?? 'pickup') === 'delivery',
            deliveryAddress: $data['delivery_address'] ?? null,
            deliveryZoneId: isset($data['delivery_zone_id']) ? (int) $data['delivery_zone_id'] : null,
            damageWaiver: (bool) ($data['damage_waiver'] ?? true),
            organizationId: isset($data['organization_id']) ? (int) $data['organization_id'] : null,
            ignoreRentalId: isset($data['rental_id']) ? (int) $data['rental_id'] : null,
        ));
    }

    public function reserve(Rental $rental, ReserveRental $reserve): RedirectResponse
    {
        $reserve->handle($rental);

        return $this->done("{$rental->number} reserved.");
    }

    public function cancel(Request $request, Rental $rental, CancelRental $cancel): RedirectResponse
    {
        $cancel->handle($rental, $request->user());

        return $this->done("{$rental->number} cancelled.");
    }

    public function sendSigningLink(Rental $rental, RentalNotifier $notifier): RedirectResponse
    {
        if ($rental->isSigned()) {
            RentalRules::fail('This agreement is already signed.');
        }

        if (! $notifier->send($rental, 'signing_link', force: true)) {
            RentalRules::fail('Add a phone number or email to the customer to send the signing link.');
        }

        return $this->done('Signing link sent.');
    }

    public function chargeCard(Request $request, Rental $rental, RentalPayments $payments): RedirectResponse
    {
        $payment = $payments->chargeCardOnFile($rental, $request->user());

        return $this->done('Charged '.Money::format($payment->amount_cents).' to the card on file.');
    }

    public function paymentLink(Request $request, Rental $rental, RentalPayments $payments): RedirectResponse
    {
        $payments->paymentLink($rental, $request->user(), route('rentals.public-sign', $rental->signing_token ?? ''));

        return $this->done('Payment link ready.');
    }

    public function recordPayment(Request $request, Rental $rental, RentalPayments $payments): RedirectResponse
    {
        $data = $request->validate([
            'tender' => ['required', Rule::enum(RentalTender::class)->only([RentalTender::CardTerminal, RentalTender::Cash, RentalTender::Cheque, RentalTender::ETransfer])],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        $payments->recordCounterPayment(
            $rental,
            RentalTender::from($data['tender']),
            (int) Money::parse($data['amount']),
            $request->user(),
            $data['reference'] ?? null,
        );

        return $this->done('Payment recorded.');
    }

    /**
     * Calendar drag: move a booking to other dates or another unit.
     */
    public function move(Request $request, Rental $rental, SaveRental $save): RedirectResponse
    {
        $data = $request->validate([
            'rental_item_id' => ['required', 'integer'],
            'rental_unit_id' => ['required', TenantRule::exists('rental_units')],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        $tz = $this->timezone();
        $items = $rental->items()->orderBy('position')->get()->map(fn ($item) => [
            'product_id' => $item->id === (int) $data['rental_item_id']
                ? (int) RentalUnit::query()->whereKey($data['rental_unit_id'])->value('product_id')
                : $item->product_id,
            'rental_unit_id' => $item->id === (int) $data['rental_item_id'] ? (int) $data['rental_unit_id'] : $item->rental_unit_id,
        ])->all();

        $save->handle([
            'starts_at' => CarbonImmutable::parse($data['starts_at'], $tz)->utc(),
            'ends_at' => CarbonImmutable::parse($data['ends_at'], $tz)->utc(),
        ], $items, $request->user(), $rental);

        // Reserved bookings must still be free after the move.
        if ($rental->status === RentalStatus::Reserved) {
            app(ReserveRental::class)->guardUnits($rental->refresh());
        }

        return $this->done("{$rental->number} moved.");
    }

    /**
     * @return array{array<string, mixed>, list<array{product_id: int, rental_unit_id: int|null}>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'organization_id' => ['nullable', TenantRule::exists('organizations')],
            'customer_id' => ['nullable', TenantRule::exists('customers')],
            'location_id' => ['required', TenantRule::exists('locations')],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'fulfillment' => ['required', Rule::enum(FulfillmentMethod::class)],
            'delivery_address' => ['nullable', 'required_if:fulfillment,delivery', 'array'],
            'delivery_address.line1' => ['nullable', 'required_if:fulfillment,delivery', 'string', 'max:255'],
            'delivery_address.city' => ['nullable', 'string', 'max:255'],
            'delivery_address.region' => ['nullable', 'string', 'max:50'],
            'delivery_address.postal_code' => ['nullable', 'required_if:fulfillment,delivery', 'string', 'max:20'],
            'delivery_zone_id' => ['nullable', TenantRule::exists('delivery_zones')],
            'damage_waiver' => ['boolean'],
            'bill_fuel_cleaning' => ['boolean'],
            'text_reminders' => ['boolean'],
            'payment_method' => ['required', Rule::enum(RentalPaymentMethod::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_id' => ['required', TenantRule::exists('products')],
            'items.*.rental_unit_id' => ['nullable', TenantRule::exists('rental_units')],
        ]);

        $tz = $this->timezone();
        $items = array_values(array_map(fn (array $item) => [
            'product_id' => (int) $item['product_id'],
            'rental_unit_id' => isset($item['rental_unit_id']) ? (int) $item['rental_unit_id'] : null,
        ], $data['items']));
        unset($data['items']);

        $data['starts_at'] = CarbonImmutable::parse($data['starts_at'], $tz)->utc();
        $data['ends_at'] = CarbonImmutable::parse($data['ends_at'], $tz)->utc();

        if ($data['fulfillment'] !== FulfillmentMethod::Delivery->value) {
            $data['delivery_address'] = null;
            $data['delivery_zone_id'] = null;
        } else {
            $data['delivery_address'] = ['country' => 'CA', ...$data['delivery_address']];
        }

        return [$data, $items];
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(?Rental $rental = null): array
    {
        return [
            'customers' => Customer::query()
                ->with(['documents', 'organization:id,name'])
                ->orderBy('first_name')
                ->get()
                ->map(fn (Customer $customer) => [
                    ...RentalPresenter::customer($customer),
                    'organization' => $customer->organization?->name,
                ]),
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name']),
            'products' => Product::query()
                ->where('kind', ProductKind::Rental->value)
                ->where('is_active', true)
                ->with(['rentalUnits' => fn ($query) => $query->orderBy('serial')])
                ->orderBy('name')
                ->get()
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category' => $product->category,
                    'day_cents' => $product->price_cents,
                    'week_cents' => $product->rate_week_cents,
                    'deposit_cents' => $product->deposit_cents,
                    'units' => $product->rentalUnits->map(fn (RentalUnit $unit) => [
                        'id' => $unit->id,
                        'serial' => $unit->serial,
                        'status' => $unit->status,
                        'service_due' => $unit->setRelation('product', $product)->serviceDue(),
                    ]),
                ]),
            'locations' => Location::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
            'zones' => DeliveryZone::query()->orderBy('position')->get(['id', 'name', 'fee_cents']),
            'paymentMethods' => array_map(
                fn (RentalPaymentMethod $method) => ['value' => $method->value, 'label' => $method->label()],
                RentalPaymentMethod::cases(),
            ),
            'settings' => [
                'damage_waiver_percent' => (float) ($rental->damage_waiver_percent ?? RentalSettings::current()->damageWaiverPercent),
                'timezone' => $this->timezone(),
            ],
        ];
    }

    private function timezone(): string
    {
        return CurrentTenant::get()->timezone ?? 'America/Toronto';
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
