<?php

namespace Tests\Feature\Yardline;

use App\Enums\DepositStatus;
use App\Enums\RentalStatus;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Rentals\Availability;
use App\Rentals\RentalPricer;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Equipment rentals: pricing, availability, the counter flow from quote to
 * return, customer self-signing and online booking. Flat 13% tax.
 */
class RentalModuleTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAPAAAABGCAQAAABYz9FxAAACl0lEQVR42u2bW3LDIAxFgyfLyP5X5n2kHx6PxzWYlySEc85XmyZG1UVCCPJ6AQAAAAAAAAAAAAAAAAAAwK/x+X6+eMEzoU/e/ac14MqHCbyJuwm7C43M/lj65X291oC0jxL4LO8RvazHjxA4Ji8J+mEpOiUmMTy9wHcSEsP+eEvG7zYBkLklYLS8FurNuTcFgVtzoY7fqgXOmZGfAhArVVOlq+kaXFpCjSi1Pt+6tmnt+3Xl/d8yGhTBZenXOobrU51Ncqz3kUYUh16jRkscT3Wp8a9/1UqNbf6RtyZIx6+l01LjSL2uZ3d6LOngWOqc2b+N0pd3742f19jtt3jXXGv9a/WkpCVqMamfpnMjXN2Ue7fFxMx7UjafLDryeoiDc7SWnHjpx3CJJ82LrFZ5dWNC/uk2Oae8ypexZZkxenXEsDnwLLFasiZY9OSdr581tmaotaSsVbPoRq9ORGgmU80YXoOULYe0OZnfmql5DZwPt06czXfXHXO8VZPeWSv3U3RiTTuCdSxuLVSPz7U0at76q5H0AeKs51UtNu9xfP+MNVRG8G9vZ2qfnz+y782IPZ8f1i/2vVstP1TJR+nYjaaL5py3BF1+LHq2Jf6fjl1SFv0hpFv5dh3j2mkbP+QYu/KbntqmxC6XzO5IIHfz7M5u2wsF97aaH3T3dm/sjiL7iib525Jp77kROC1R2Tptt5rd2aNbNLXJ6Ejg9qiwrUbTo8lMM2kh3QucF3DMZb54q7Du8tKoUtGZwPnEaL3ZKN0IeRByCoH9XIuLTas5v5XlzrBYpI7rBR0j+7z44KLRIVOAjHHtvm+fVV6HAl9bIWN7QcdlvTm/ceXU6PlTIwJXpGnkfaDAY4srAAAAAAAAAAAAABDkDz4VeEPQ4wq3AAAAAElFTkSuQmCC';

    private const TZ = 'America/Toronto';

    private User $user;

    private Location $yard;

    private Product $buggy;

    private RentalUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        config(['yardline.tax.driver' => 'flat', 'yardline.tax.flat_rate' => 0.13, 'yardline.payments.driver' => 'fake']);
        Storage::fake(config('yardline.files_disk'));
        // A Monday.
        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', self::TZ));

        $this->user = $this->signInToNewTenant();
        $this->yard = Location::query()->firstOrFail();
        $this->buggy = Product::factory()->rental(deposit: 50_000)->create([
            'name' => 'Power buggy',
            'price_cents' => 16_500,
            'rate_week_cents' => 49_500,
            'fuel_charge_cents' => 3_500,
            'cleaning_fee_cents' => 5_000,
            'bookable_online' => true,
        ]);
        $this->unit = RentalUnit::factory()->create(['product_id' => $this->buggy->id, 'serial' => 'PB-01', 'hour_meter' => 300]);
    }

    private function local(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, self::TZ);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function quote(array $attributes = [], ?RentalUnit $unit = null): Rental
    {
        $customer = Customer::factory()->create();

        $this->post(route('rentals.store'), [
            'customer_id' => $customer->id,
            'location_id' => $this->yard->id,
            'starts_at' => '2026-11-03T07:00',
            'ends_at' => '2026-11-04T17:00',
            'fulfillment' => 'pickup',
            'damage_waiver' => true,
            'bill_fuel_cleaning' => true,
            'payment_method' => 'counter',
            'items' => [['product_id' => $this->buggy->id, 'rental_unit_id' => ($unit ?? $this->unit)->id]],
            ...$attributes,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Rental::query()->latest('id')->firstOrFail();
    }

    public function test_the_cheapest_mix_of_rates_wins(): void
    {
        $pricer = app(RentalPricer::class);

        // Thursday morning to the next Wednesday evening: a week beats seven days.
        $quote = $pricer->quote($this->buggy, $this->local('2026-11-05 07:00'), $this->local('2026-11-11 17:00'));
        $this->assertSame(49_500, $quote->cents);
        $this->assertTrue($quote->beatsDailyRate());

        // Two days is two days.
        $quote = $pricer->quote($this->buggy, $this->local('2026-11-03 07:00'), $this->local('2026-11-04 17:00'));
        $this->assertSame(33_000, $quote->cents);
    }

    public function test_sunday_is_free_with_a_saturday_pickup(): void
    {
        $saw = Product::factory()->rental()->create(['price_cents' => 5_500]);

        $pricer = app(RentalPricer::class);

        // Out Saturday, back Monday morning: one day.
        $this->assertSame(5_500, $pricer->quote($saw, $this->local('2026-11-07 08:00'), $this->local('2026-11-09 08:00'))->cents);
        // Kept through Monday: two days.
        $this->assertSame(11_000, $pricer->quote($saw, $this->local('2026-11-07 08:00'), $this->local('2026-11-09 17:00'))->cents);
    }

    public function test_a_unit_cannot_be_double_booked(): void
    {
        $first = $this->quote();
        $this->post(route('rentals.reserve', $first))->assertSessionHasNoErrors();
        $this->assertSame(RentalStatus::Reserved, $first->refresh()->status);

        $availability = app(Availability::class);
        $this->assertFalse($availability->isFree($this->unit, $this->local('2026-11-04 08:00'), $this->local('2026-11-05 17:00')));
        $this->assertTrue($availability->isFree($this->unit, $this->local('2026-11-05 08:00'), $this->local('2026-11-06 17:00')));

        $second = $this->quote(['starts_at' => '2026-11-04T08:00', 'ends_at' => '2026-11-05T17:00']);
        $this->post(route('rentals.reserve', $second))->assertSessionHasErrors();
        $this->assertSame(RentalStatus::Quote, $second->refresh()->status);
    }

    public function test_a_unit_due_for_service_is_locked_out(): void
    {
        $this->unit->forceFill(['service_interval_hours' => 100, 'last_service_hours' => 150, 'hour_meter' => 260])->save();

        $this->assertTrue($this->unit->refresh()->serviceDue());
        $this->assertNotNull(app(Availability::class)->problem($this->unit, $this->local('2026-11-03 07:00'), $this->local('2026-11-04 17:00')));

        $this->post(route('maintenance.store'), [
            'rental_unit_id' => $this->unit->id,
            'kind' => 'service',
            'done' => true,
            'description' => '100-hr service',
            'hour_meter' => 260,
            'cost' => '120.00',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($this->unit->refresh()->serviceDue());
    }

    public function test_counter_flow_from_quote_to_return(): void
    {
        $rental = $this->quote();
        $this->assertStringStartsWith('R-', $rental->number);
        // Two days at $165, a 12% waiver and 13% tax.
        $this->assertSame(33_000, $rental->rental_cents);
        $this->assertSame(3_960, $rental->waiver_cents);
        $this->assertSame(50_000, $rental->deposit_cents);

        $this->post(route('rentals.reserve', $rental))->assertSessionHasNoErrors();

        // Can't go out unsigned or unpaid.
        $this->post(route('rentals.inspect.store', [$rental, 'check-out']), [
            'units' => [['rental_item_id' => $rental->items()->value('id'), 'hour_meter' => 300, 'fuel' => 'full', 'clean' => true]],
        ])->assertSessionHasErrors();

        $this->post(route('rentals.agreement.sign', $rental), [
            'signer_name' => 'Pat Walker',
            'signature' => self::SIGNATURE,
            'acknowledged_training' => true,
            'licence_photo' => UploadedFile::fake()->image('licence.jpg'),
        ])->assertSessionHasNoErrors();
        $this->assertTrue($rental->refresh()->isSigned());
        $this->assertNotNull($rental->agreement?->pdf_path);

        $this->post(route('rentals.payments.store', $rental), ['tender' => 'card_terminal', 'amount' => $rental->total_cents / 100])
            ->assertSessionHasNoErrors();

        $item = $rental->items()->firstOrFail();
        $this->post(route('rentals.inspect.store', [$rental, 'check-out']), [
            'units' => [['rental_item_id' => $item->id, 'hour_meter' => 300, 'fuel' => 'full', 'clean' => true]],
            'deposit_tender' => 'card_terminal',
        ])->assertSessionHasNoErrors();
        $rental->refresh();
        $this->assertSame(RentalStatus::Out, $rental->status);
        $this->assertSame(DepositStatus::Held, $rental->deposit_status);

        // Back on time, low on fuel and dirty.
        $this->travelTo($this->local('2026-11-04 16:30'));
        $this->post(route('rentals.inspect.store', [$rental, 'return']), [
            'units' => [['rental_item_id' => $item->id, 'hour_meter' => 306.5, 'fuel' => 'low', 'clean' => false]],
        ])->assertSessionHasNoErrors();

        $rental->refresh();
        $this->assertSame(RentalStatus::Returned, $rental->status);
        $this->assertSame(['cleaning', 'fuel'], $rental->charges()->pluck('kind')->map(fn ($kind) => $kind->value ?? $kind)->sort()->values()->all());
        $this->assertNotSame(DepositStatus::Held, $rental->deposit_status);
        $this->assertSame(0, $rental->balanceCents());
        $this->assertSame('306.5', $this->unit->refresh()->hour_meter);
    }

    public function test_customers_can_sign_from_a_link(): void
    {
        $rental = $this->quote();
        $this->post(route('rentals.reserve', $rental));
        auth()->logout();

        $this->get(route('rentals.public-sign', $rental->signing_token))->assertOk();
        $this->post(route('rentals.public-sign.store', $rental->signing_token), [
            'signer_name' => 'Pat Walker',
            'signature' => self::SIGNATURE,
            'acknowledged_training' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame('link', $rental->refresh()->agreement?->signed_via);
        $this->get(route('rentals.public-sign', 'not-a-token'))->assertNotFound();
    }

    public function test_equipment_can_be_booked_online(): void
    {
        $tenant = CurrentTenant::get();
        $this->assertInstanceOf(Tenant::class, $tenant);
        auth()->logout();

        $this->get(route('book.show', $tenant->slug))->assertOk();
        $this->getJson(route('book.quote', ['tenant' => $tenant->slug, 'product_id' => $this->buggy->id, 'pickup_on' => '2026-11-03', 'return_on' => '2026-11-04', 'damage_waiver' => 1]))
            ->assertOk()
            ->assertJsonPath('valid', true);

        $this->post(route('book.store', $tenant->slug), [
            'product_id' => $this->buggy->id,
            'pickup_on' => '2026-11-03',
            'return_on' => '2026-11-04',
            'damage_waiver' => true,
            'first_name' => 'Helen',
            'last_name' => 'Wu',
            'email' => 'helen@example.com',
            'phone' => '289-555-0125',
        ])->assertRedirect();

        $rental = Rental::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame('online', $rental->source);
        $this->assertSame(RentalStatus::Reserved, $rental->status);
        $this->assertSame($this->unit->id, $rental->items()->withoutGlobalScopes()->value('rental_unit_id'));
    }

    public function test_rental_pages_render(): void
    {
        $rental = $this->quote();

        $this->get(route('fleet.index'))->assertOk();
        $this->get(route('fleet.units.create'))->assertOk();
        $this->get(route('fleet.units.show', $this->unit))->assertOk();
        $this->get(route('rentals.calendar'))->assertOk();
        $this->get(route('rentals.index'))->assertOk();
        $this->get(route('rentals.create'))->assertOk();
        $this->get(route('rentals.show', $rental))->assertOk();
        $this->get(route('rentals.agreement', $rental))->assertOk();
        $this->get(route('rentals.inspect', [$rental, 'check-out']))->assertOk();
        $this->get(route('maintenance.index'))->assertOk();
        $this->get(route('rental-payments.index'))->assertOk();
        $this->get(route('rental-settings.edit'))->assertOk();
        $this->get(route('rentals.preview', [
            'location_id' => $this->yard->id,
            'starts_at' => '2026-11-03T07:00',
            'ends_at' => '2026-11-04T17:00',
            'items' => [['product_id' => $this->buggy->id]],
        ]))->assertOk();
    }
}
