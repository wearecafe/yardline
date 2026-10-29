<?php

namespace Tests\Feature\YardRunner;

use App\Accounting\QuickBooks\QuickBooks;
use App\Enums\IntegrationProvider;
use App\Models\Customer;
use App\Models\Integration;
use App\Models\Location;
use App\Models\Product;
use App\Models\QuickBooksSync;
use App\Models\Rental;
use App\Models\RentalUnit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeQuickBooks;
use Tests\TestCase;

/**
 * Rentals in QuickBooks: an invoice when the equipment goes out, updated
 * at return; payments taken before it goes out wait for that invoice.
 */
class QuickBooksRentalSyncTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAPAAAABGCAQAAABYz9FxAAACl0lEQVR42u2bW3LDIAxFgyfLyP5X5n2kHx6PxzWYlySEc85XmyZG1UVCCPJ6AQAAAAAAAAAAAAAAAAAAwK/x+X6+eMEzoU/e/ac14MqHCbyJuwm7C43M/lj65X291oC0jxL4LO8RvazHjxA4Ji8J+mEpOiUmMTy9wHcSEsP+eEvG7zYBkLklYLS8FurNuTcFgVtzoY7fqgXOmZGfAhArVVOlq+kaXFpCjSi1Pt+6tmnt+3Xl/d8yGhTBZenXOobrU51Ncqz3kUYUh16jRkscT3Wp8a9/1UqNbf6RtyZIx6+l01LjSL2uZ3d6LOngWOqc2b+N0pd3742f19jtt3jXXGv9a/WkpCVqMamfpnMjXN2Ue7fFxMx7UjafLDryeoiDc7SWnHjpx3CJJ82LrFZ5dWNC/uk2Oae8ypexZZkxenXEsDnwLLFasiZY9OSdr581tmaotaSsVbPoRq9ORGgmU80YXoOULYe0OZnfmql5DZwPt06czXfXHXO8VZPeWSv3U3RiTTuCdSxuLVSPz7U0at76q5H0AeKs51UtNu9xfP+MNVRG8G9vZ2qfnz+y782IPZ8f1i/2vVstP1TJR+nYjaaL5py3BF1+LHq2Jf6fjl1SFv0hpFv5dh3j2mkbP+QYu/KbntqmxC6XzO5IIHfz7M5u2wsF97aaH3T3dm/sjiL7iib525Jp77kROC1R2Tptt5rd2aNbNLXJ6Ejg9qiwrUbTo8lMM2kh3QucF3DMZb54q7Du8tKoUtGZwPnEaL3ZKN0IeRByCoH9XIuLTas5v5XlzrBYpI7rBR0j+7z44KLRIVOAjHHtvm+fVV6HAl9bIWN7QcdlvTm/ceXU6PlTIwJXpGnkfaDAY4srAAAAAAAAAAAAABDkDz4VeEPQ4wq3AAAAAElFTkSuQmCC';

    private const TZ = 'America/Toronto';

    public function test_a_rental_is_invoiced_at_check_out_and_updated_at_return(): void
    {
        config(['yardrunner.tax.driver' => 'flat', 'yardrunner.tax.flat_rate' => 0.13, 'yardrunner.payments.driver' => 'fake']);
        Storage::fake(config('yardrunner.files_disk'));
        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', self::TZ));
        $qbo = (new FakeQuickBooks)->install();

        $this->signInToNewTenant();
        $yard = Location::query()->firstOrFail();
        Integration::query()->create([
            'provider' => IntegrationProvider::QuickBooks,
            'external_account' => '9130',
            'credentials' => ['access_token' => 'access', 'refresh_token' => 'refresh', 'access_expires_at' => now()->addHour()->toIso8601String()],
            'settings' => [
                'start_date' => '2026-11-01',
                'accounts' => ['sales' => '1', 'delivery' => '2', 'rental' => '3', 'damage_waiver' => '4', 'restocking' => '5', 'adjustments' => '6', 'pallet_deposits' => '7', 'stripe_clearing' => '8', 'undeposited' => '9'],
                'tax_codes' => ['ON' => '20', 'QC' => '21', 'other' => '22', 'exempt' => '23'],
            ],
        ]);
        QuickBooks::forget();

        $buggy = Product::factory()->rental(deposit: 50_000)->create(['name' => 'Power buggy', 'price_cents' => 16_500, 'fuel_charge_cents' => 3_500, 'cleaning_fee_cents' => 5_000]);
        $unit = RentalUnit::factory()->create(['product_id' => $buggy->id, 'serial' => 'PB-01', 'hour_meter' => 300]);

        $this->post(route('rentals.store'), [
            'customer_id' => Customer::factory()->create(['first_name' => 'Pat', 'last_name' => 'Walker'])->id,
            'location_id' => $yard->id,
            'starts_at' => '2026-11-03T07:00',
            'ends_at' => '2026-11-04T17:00',
            'fulfillment' => 'pickup',
            'damage_waiver' => true,
            'bill_fuel_cleaning' => true,
            'payment_method' => 'counter',
            'items' => [['product_id' => $buggy->id, 'rental_unit_id' => $unit->id]],
        ])->assertSessionHasNoErrors();
        $rental = Rental::query()->latest('id')->firstOrFail();
        $this->post(route('rentals.reserve', $rental))->assertSessionHasNoErrors();
        $this->post(route('rentals.agreement.sign', $rental), [
            'signer_name' => 'Pat Walker',
            'signature' => self::SIGNATURE,
            'acknowledged_training' => true,
            'licence_photo' => UploadedFile::fake()->image('licence.jpg'),
        ])->assertSessionHasNoErrors();

        // Paid at the counter before it goes out: waits for the invoice.
        $this->post(route('rentals.payments.store', $rental), ['tender' => 'card_terminal', 'amount' => $rental->refresh()->total_cents / 100])->assertSessionHasNoErrors();
        $this->assertSame([], $qbo->created('Invoice'));
        $this->assertSame([], $qbo->created('Payment'));

        $item = $rental->items()->firstOrFail();
        $this->post(route('rentals.inspect.store', [$rental, 'check-out']), [
            'units' => [['rental_item_id' => $item->id, 'hour_meter' => 300, 'fuel' => 'full', 'clean' => true]],
            'deposit_tender' => 'card_terminal',
        ])->assertSessionHasNoErrors();

        $invoice = $qbo->last('Invoice');
        $this->assertSame($rental->number, $invoice['DocNumber']);
        $this->assertSame('Cash sales', $qbo->records['Customer'][$invoice['CustomerRef']['value']]['DisplayName']);
        $this->assertStringContainsString('Pat Walker', $invoice['PrivateNote']);
        $this->assertStringContainsString('PB-01', $invoice['Line'][0]['Description']);
        $this->assertSame('Service', collect($qbo->records['Item'])->firstWhere('Name', 'Power buggy')['Type']);
        $this->assertSame($rental->refresh()->total_cents, (int) round($invoice['TotalAmt'] * 100));

        // The counter payment followed, applied to it; the deposit hold didn't.
        $payments = $qbo->created('Payment');
        $this->assertCount(1, $payments);
        $this->assertSame($invoice['Id'], $payments[0]['Line'][0]['LinkedTxn'][0]['TxnId']);
        $this->assertSame('8', $payments[0]['DepositToAccountRef']['value']);

        // Back dirty and low on fuel: the same invoice gains the charges.
        $this->travelTo(CarbonImmutable::parse('2026-11-04 16:30', self::TZ));
        $this->post(route('rentals.inspect.store', [$rental, 'return']), [
            'units' => [['rental_item_id' => $item->id, 'hour_meter' => 306.5, 'fuel' => 'low', 'clean' => false]],
        ])->assertSessionHasNoErrors();

        $this->assertCount(1, $qbo->created('Invoice'));
        $updated = $qbo->records['Invoice'][$invoice['Id']];
        $this->assertGreaterThan((int) $invoice['SyncToken'], (int) $updated['SyncToken']);
        $this->assertSame($rental->refresh()->total_cents, (int) round($updated['TotalAmt'] * 100));
        $descriptions = array_column($updated['Line'], 'Description');
        $this->assertContains('Fuel charge', array_map(fn ($l) => $qbo->records['Item'][$l['SalesItemLineDetail']['ItemRef']['value']]['Name'], $updated['Line']));
        $this->assertNotEmpty($descriptions);
        $this->assertSame('synced', QuickBooksSync::for($rental)?->status);
    }
}
