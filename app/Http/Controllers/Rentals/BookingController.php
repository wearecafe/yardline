<?php

namespace App\Http\Controllers\Rentals;

use App\Enums\DocumentKind;
use App\Enums\ProductKind;
use App\Enums\RentalPaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\Location;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Rentals\Availability;
use App\Rentals\Payments\RentalPayments;
use App\Rentals\RentalEstimator;
use App\Rentals\RentalSettings;
use App\Rentals\ReserveRental;
use App\Rentals\SaveRental;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The yard's public equipment booking page: browse, check live
 * availability, see the price, book and pay. The agreement is signed
 * online and the licence is shown at the counter.
 *
 * A contractor signed in to the portal for the same yard gets the portal's
 * navigation, their details filled in, and the rental booked to their
 * account.
 */
class BookingController extends Controller
{
    public function show(Request $request, Tenant $tenant, Availability $availability): Response
    {
        CurrentTenant::set($tenant);
        $settings = RentalSettings::for($tenant);
        $customer = $this->portalCustomer($request, $tenant);

        $products = Product::query()
            ->where('kind', ProductKind::Rental->value)
            ->where('is_active', true)
            ->where('bookable_online', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return Inertia::render('public/book', [
            'yard' => $tenant->only('name', 'slug'),
            'settings' => [
                'damage_waiver_percent' => $settings->damageWaiverPercent,
                'free_sunday' => $settings->freeSundayWithSaturday,
                'opening_time' => $settings->openingTime,
                'closing_time' => $settings->closingTime,
            ],
            'today' => CarbonImmutable::now($tenant->timezone)->toDateString(),
            'products' => $products->map(function (Product $product) use ($availability, $tenant) {
                $next = $availability->nextAvailable($product, CarbonImmutable::now());

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category' => $product->category ?? 'Other',
                    'use_case' => $product->use_case,
                    'day_cents' => $product->price_cents,
                    'week_cents' => $product->rate_week_cents,
                    'deposit_cents' => $product->deposit_cents,
                    'available_now' => $next !== null && $next->lessThanOrEqualTo(CarbonImmutable::now()->addHours(2)),
                    'available_from' => $next?->setTimezone($tenant->timezone)->toDateString(),
                ];
            }),
            'booked' => $request->session()->get('booked'),
            // Same shape the portal middleware shares, so the page can use the portal's layout.
            'portal' => $customer === null ? null : [
                'customer' => ['id' => $customer->id, 'name' => $customer->full_name, 'first_name' => $customer->first_name],
                'organization' => $customer->organization?->only('id', 'name', 'account_number'),
                'yard' => $tenant->only('name', 'slug'),
            ],
            'account' => $customer === null ? null : [
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name ?? '',
                'email' => $customer->email ?? '',
                'phone' => $customer->phone ?? '',
                'on_file' => $this->onFile($customer),
            ],
        ]);
    }

    /**
     * Days in a window when no unit of the product is free from opening to
     * closing, for the booking page's calendar.
     */
    public function availability(Request $request, Tenant $tenant, Availability $availability): JsonResponse
    {
        CurrentTenant::set($tenant);
        $settings = RentalSettings::for($tenant);

        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'from' => ['required', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'min:1', 'max:42'],
        ]);

        $product = Product::query()
            ->where('kind', ProductKind::Rental->value)
            ->where('bookable_online', true)
            ->findOrFail((int) $data['product_id']);

        $today = CarbonImmutable::now($tenant->timezone)->startOfDay();
        $from = CarbonImmutable::parse($data['from'], $tenant->timezone)->startOfDay()->max($today);
        $limit = $today->addDays(365);
        $booked = [];

        for ($i = 0; $i < (int) ($data['days'] ?? 42); $i++) {
            $day = $from->addDays($i);

            if ($day->greaterThan($limit)) {
                break;
            }

            $open = $day->setTimeFromTimeString($settings->openingTime);
            $close = $day->setTimeFromTimeString($settings->closingTime);

            if ($availability->freeUnits($product, $open, $close)->isEmpty()) {
                $booked[] = $day->toDateString();
            }
        }

        return response()->json(['booked' => $booked]);
    }

    public function quote(Request $request, Tenant $tenant, RentalEstimator $estimator): JsonResponse
    {
        CurrentTenant::set($tenant);
        $data = $this->dates($request, $tenant);
        $customer = $this->portalCustomer($request, $tenant);

        return response()->json($estimator->estimate(
            items: [['product_id' => (int) $request->integer('product_id')]],
            start: $data['start'],
            end: $data['end'],
            locationId: null,
            delivery: false,
            deliveryAddress: null,
            deliveryZoneId: null,
            damageWaiver: $request->boolean('damage_waiver', true),
            organizationId: $customer?->organization_id,
        ));
    }

    public function store(Request $request, Tenant $tenant, SaveRental $save, RentalPayments $payments): HttpResponse
    {
        CurrentTenant::set($tenant);
        $account = $this->portalCustomer($request, $tenant);
        // Signed in, the account is who's renting: only the phone can change here.
        $identity = $account === null ? 'required' : 'nullable';

        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'pickup_on' => ['required', 'date', 'after_or_equal:today'],
            'return_on' => ['required', 'date', 'after_or_equal:pickup_on'],
            'damage_waiver' => ['boolean'],
            'first_name' => [$identity, 'string', 'max:255'],
            'last_name' => [$identity, 'string', 'max:255'],
            'email' => [$identity, 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        $product = Product::query()
            ->where('kind', ProductKind::Rental->value)
            ->where('bookable_online', true)
            ->findOrFail((int) $data['product_id']);
        $dates = $this->dates($request, $tenant);

        if ($account !== null && $account->phone !== $data['phone']) {
            $account->update(['phone' => $data['phone']]);
        }

        // Match a returning guest by email; never attach to their organization from here.
        $customer = $account ?? Customer::query()->whereRaw('lower(email) = ?', [strtolower($data['email'])])->first()
            ?? Customer::query()->create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
            ]);

        // Online bookings are created by the yard owner's account.
        $owner = User::query()->where('tenant_id', $tenant->id)->orderBy('id')->firstOrFail();

        $rental = $save->handle([
            'customer_id' => $customer->id,
            'organization_id' => $account?->organization_id,
            'location_id' => Location::query()->where('is_default', true)->value('id') ?? Location::query()->value('id'),
            'starts_at' => $dates['start']->utc(),
            'ends_at' => $dates['end']->utc(),
            'fulfillment' => 'pickup',
            'damage_waiver' => (bool) ($data['damage_waiver'] ?? true),
            'payment_method' => RentalPaymentMethod::PaymentLink->value,
        ], [['product_id' => $product->id]], $owner);

        $rental->forceFill(['source' => 'online'])->save();

        app(ReserveRental::class)->handle($rental);

        $payment = $payments->paymentLink($rental, null, route('rentals.public-sign', $rental->signing_token ?? ''));

        return Inertia::location((string) $payment->checkout_url);
    }

    /**
     * The contractor signed in to this yard's portal, if any.
     */
    private function portalCustomer(Request $request, Tenant $tenant): ?Customer
    {
        if (! $request->hasSession() || $request->session()->get('portal.tenant_id') !== $tenant->id) {
            return null;
        }

        $id = $request->session()->get('portal.customer_id');

        return is_int($id) ? Customer::query()->with('organization')->find($id) : null;
    }

    /**
     * Current documents on the account, so the renter knows what to bring.
     *
     * @return array<int, array{kind: string, label: string, detail: string}>
     */
    private function onFile(Customer $customer): array
    {
        return $customer->documents()
            ->orderByDesc('verified_at')
            ->get()
            ->filter(fn (CustomerDocument $doc) => $doc->isCurrent())
            ->unique(fn (CustomerDocument $doc) => $doc->kind->value)
            ->map(function (CustomerDocument $doc) {
                $detail = 'On file';

                if ($doc->expires_on !== null) {
                    $detail = 'On file, expires '.$doc->expires_on->format('M j, Y');
                } elseif ($doc->kind === DocumentKind::DriversLicence && $doc->verified_at !== null) {
                    $detail = 'Verified '.$doc->verified_at->format('M Y');
                }

                return ['kind' => $doc->kind->value, 'label' => $doc->kind->label(), 'detail' => $detail];
            })
            ->values()
            ->all();
    }

    /**
     * Whole days: pick up at opening, return by closing.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    private function dates(Request $request, Tenant $tenant): array
    {
        $settings = RentalSettings::for($tenant);
        $pickup = $request->string('pickup_on')->toString() ?: CarbonImmutable::now($tenant->timezone)->addDay()->toDateString();
        $return = $request->string('return_on')->toString() ?: $pickup;

        return [
            'start' => CarbonImmutable::parse($pickup.' '.$settings->openingTime, $tenant->timezone),
            'end' => CarbonImmutable::parse($return.' '.$settings->closingTime, $tenant->timezone),
        ];
    }
}
