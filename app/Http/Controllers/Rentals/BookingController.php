<?php

namespace App\Http\Controllers\Rentals;

use App\Enums\ProductKind;
use App\Enums\RentalPaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Customer;
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
 */
class BookingController extends Controller
{
    public function show(Request $request, Tenant $tenant, Availability $availability): Response
    {
        CurrentTenant::set($tenant);
        $settings = RentalSettings::for($tenant);
        $tomorrow = CarbonImmutable::now($tenant->timezone)->addDay()->setTimeFromTimeString($settings->openingTime);

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
        ]);
    }

    public function quote(Request $request, Tenant $tenant, RentalEstimator $estimator): JsonResponse
    {
        CurrentTenant::set($tenant);
        $data = $this->dates($request, $tenant);

        return response()->json($estimator->estimate(
            items: [['product_id' => (int) $request->integer('product_id')]],
            start: $data['start'],
            end: $data['end'],
            locationId: null,
            delivery: false,
            deliveryAddress: null,
            deliveryZoneId: null,
            damageWaiver: $request->boolean('damage_waiver', true),
        ));
    }

    public function store(Request $request, Tenant $tenant, SaveRental $save, RentalPayments $payments): HttpResponse
    {
        CurrentTenant::set($tenant);

        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'pickup_on' => ['required', 'date', 'after_or_equal:today'],
            'return_on' => ['required', 'date', 'after_or_equal:pickup_on'],
            'damage_waiver' => ['boolean'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        $product = Product::query()
            ->where('kind', ProductKind::Rental->value)
            ->where('bookable_online', true)
            ->findOrFail((int) $data['product_id']);
        $dates = $this->dates($request, $tenant);

        // Match a returning customer by email; never attach to their organization from here.
        $customer = Customer::query()->whereRaw('lower(email) = ?', [strtolower($data['email'])])->first()
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
            'organization_id' => null,
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
