<?php

namespace Tests\Feature\YardRunner;

use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTender;
use App\Enums\PaymentTerms;
use App\Enums\RentalPaymentKind;
use App\Enums\RentalTender;
use App\Enums\StockMovementType;
use App\Enums\Unit;
use App\Enums\UserRole;
use App\Inventory\InventoryLedger;
use App\Models\AuditEvent;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Rental;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Payments\Refunds\CardRefundFailed;
use App\Payments\Refunds\CardRefundResult;
use App\Payments\Refunds\CardRefunds;
use App\Rentals\Payments\RentalPayments;
use App\Support\StripeGateway;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Refunds on order payments and credit notes: back to the card through
 * Stripe (on the yard's connected account), or by hand; refund and cancel;
 * and Stripe's refund webhooks. Flat 13% tax.
 */
class RefundsTest extends TestCase
{
    use RefreshDatabase;

    private const CONNECT_SECRET = 'whsec_test_connect';

    private User $owner;

    private Location $yard;

    private Product $mortar;

    private Organization $org;

    /** @var list<array{payment: int, cents: int, metadata: array<string, string>}> */
    private array $cardRefunds = [];

    private ?string $refuseWith = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'yardrunner.tax.driver' => 'flat',
            'yardrunner.tax.flat_rate' => 0.13,
            'yardrunner.payments.driver' => 'fake',
            'yardrunner.payments.application_fee' => ['percent' => 0.5, 'fixed_cents' => 0],
            'services.stripe.connect_webhook_secret' => self::CONNECT_SECRET,
        ]);

        // Records what goes to the card, and can refuse it.
        $this->app->instance(CardRefunds::class, new class($this) implements CardRefunds
        {
            public function __construct(private RefundsTest $test) {}

            public function refund(Payment $payment, int $cents, string $idempotencyKey, array $metadata): CardRefundResult
            {
                return $this->test->cardRefund($payment, $cents, $metadata);
            }
        });

        $this->owner = $this->signInToNewTenant();
        CurrentTenant::get()?->forceFill(['stripe_account_id' => 'acct_yard', 'stripe_charges_enabled' => true])->save();
        $this->yard = Location::query()->firstOrFail();

        $this->mortar = Product::factory()->create(['name' => 'Type S mortar', 'unit' => Unit::Bag, 'price_cents' => 1_000]);
        app(InventoryLedger::class)->record($this->mortar, $this->yard, 200, StockMovementType::Receipt);
        $this->org = Organization::factory()->withCredit(10_000_000, PaymentTerms::Net30)->create();
    }

    /**
     * @param  array<string, string>  $metadata
     */
    public function cardRefund(Payment $payment, int $cents, array $metadata): CardRefundResult
    {
        if ($this->refuseWith !== null) {
            throw new CardRefundFailed($this->refuseWith);
        }

        $this->cardRefunds[] = ['payment' => $payment->id, 'cents' => $cents, 'metadata' => $metadata];

        return new CardRefundResult('re_test_'.count($this->cardRefunds), 'succeeded');
    }

    private function person(UserRole $role): User
    {
        $user = User::factory()->create(['tenant_id' => $this->owner->tenant_id]);
        $user->setRoles([$role])->save();

        if ($user->isYardScoped()) {
            $user->setYards([$this->yard->id]);
        }

        return $user->refresh();
    }

    /** A confirmed, prepaid order for mortar: $11.30 a bag with tax. */
    private function order(int $bags): Order
    {
        $this->post(route('orders.store'), ['type' => 'sale', 'organization_id' => $this->org->id, 'location_id' => $this->yard->id, 'fulfillment' => 'pickup']);
        $order = Order::query()->latest('id')->firstOrFail();
        $this->post(route('orders.lines.store', $order), ['product_id' => $this->mortar->id, 'quantity' => $bags])->assertSessionHasNoErrors();
        $this->post(route('orders.confirm', $order), ['settlement' => 'prepaid'])->assertSessionHasNoErrors();

        return $order->refresh();
    }

    /** Pays what's owing through a card payment link. */
    private function payByCard(Order $order): Payment
    {
        $this->post(route('orders.payment-link', $order))->assertSessionHasNoErrors();
        $payment = $order->payments()->latest('id')->firstOrFail();
        $this->get(route('dev.fake-checkout', $payment->stripe_checkout_session_id))->assertRedirect();

        return $payment->refresh();
    }

    public function test_a_card_payment_goes_back_to_the_card(): void
    {
        $order = $this->order(10);
        $payment = $this->payByCard($order);
        $this->assertSame(11_300, $order->refresh()->amount_paid_cents);

        $this->post(route('orders.payments.refund', [$order, $payment]), ['amount' => '50.00', 'reason' => 'Short a pallet'])
            ->assertSessionHasNoErrors();

        $this->assertSame([['payment' => $payment->id, 'cents' => 5_000]], array_map(fn ($r) => ['payment' => $r['payment'], 'cents' => $r['cents']], $this->cardRefunds));
        $this->assertSame((string) $order->id, $this->cardRefunds[0]['metadata']['yardrunner_order_id']);

        $refund = Refund::query()->sole();
        $this->assertSame(PaymentTender::StripeCheckout, $refund->tender);
        $this->assertSame(PaymentState::Succeeded, $refund->status);
        $this->assertSame('re_test_1', $refund->stripe_refund_id);
        $this->assertSame($this->owner->id, $refund->refunded_by);
        $this->assertSame(5_000, $payment->refresh()->refunded_cents);
        // YardRunner's 0.5% ($0.57 on $113) goes back to the yard in proportion.
        $this->assertSame(25, $refund->fee_returned_cents);

        $order->refresh();
        $this->assertSame(6_300, $order->amount_paid_cents);
        $this->assertSame(PaymentStatus::Partial, $order->payment_status);
        $this->assertSame(5_000, $order->balanceCents());

        // No more than is left on it.
        $this->post(route('orders.payments.refund', [$order, $payment]), ['amount' => '63.01'])->assertSessionHasErrors('amount');
        $this->assertCount(1, $this->cardRefunds);

        $this->get(route('orders.show', $order))->assertInertia(fn (Assert $page) => $page
            ->where('refunding.payments.0.refundable_cents', 6_300)
            ->where('refunding.refunds.0.amount_cents', 5_000)
            ->where('refunding.refunds.0.label', 'To the card'));

        $this->assertTrue(AuditEvent::query()->where('event', 'refunded')->where('subject_id', $order->id)->exists());
    }

    public function test_yardrunners_fee_comes_off_card_fees_when_refunded(): void
    {
        $order = $this->order(10);
        $payment = $this->payByCard($order);
        // Stripe's fee plus YardRunner's $0.57, as Stripe reports it.
        $payment->forceFill(['fee_cents' => 385, 'fee_checked_at' => now()])->saveQuietly();

        $this->post(route('orders.payments.refund', [$order, $payment]))->assertSessionHasNoErrors();
        $this->assertSame(57, Refund::query()->sole()->fee_returned_cents);

        // Stripe's fee stays a cost to the yard; YardRunner's doesn't.
        $range = ['period' => 'custom', 'from' => now()->subWeek()->toDateString(), 'to' => now()->addWeek()->toDateString(), 'compare' => 'none'];
        $this->get(route('reports.profit', $range))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('summary.lines.card_fees', 328));
    }

    public function test_a_cancelled_rentals_card_refund_gives_yardrunners_fee_back(): void
    {
        $rental = Rental::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $rental->payments()->create([
            'kind' => RentalPaymentKind::Charge,
            'tender' => RentalTender::CardOnFile,
            'status' => PaymentState::Succeeded,
            'amount_cents' => 20_000,
            'stripe_payment_intent_id' => 'pi_fake_rental',
            'succeeded_at' => now(),
        ]);

        app(RentalPayments::class)->refundAll($rental, $this->owner);

        $refund = $rental->payments()->where('kind', RentalPaymentKind::Refund->value)->sole();
        $this->assertSame(100, $refund->fee_returned_cents);
    }

    public function test_the_fee_given_back_is_in_proportion_to_the_refund(): void
    {
        $this->assertSame(57, StripeGateway::applicationFee(11_300));
        $this->assertSame(25, StripeGateway::applicationFeeReturned(11_300, 5_000));
        $this->assertSame(57, StripeGateway::applicationFeeReturned(11_300, 11_300));
        $this->assertSame(57, StripeGateway::applicationFeeReturned(11_300, 99_999));
        $this->assertSame(0, StripeGateway::applicationFeeReturned(11_300, 0));
    }

    public function test_other_payments_are_recorded_as_handed_back(): void
    {
        $order = $this->order(10);
        $this->post(route('orders.payments.store', $order), ['tender' => 'cash', 'amount' => '113.00'])->assertSessionHasNoErrors();
        $cash = $order->payments()->sole();

        $this->post(route('orders.payments.refund', [$order, $cash]))->assertSessionHasNoErrors();

        $this->assertSame([], $this->cardRefunds);
        $this->assertSame(PaymentTender::Cash, Refund::query()->sole()->tender);
        $this->assertSame(0, $order->refresh()->amount_paid_cents);
        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status);

        // Nothing left on it.
        $this->post(route('orders.payments.refund', [$order, $cash]), ['amount' => '1'])->assertSessionHasErrors('amount');
    }

    public function test_a_payment_from_another_order_is_not_found(): void
    {
        $order = $this->order(1);
        $payment = $this->payByCard($order);
        $other = $this->order(1);

        $this->post(route('orders.payments.refund', [$other, $payment]))->assertNotFound();
    }

    public function test_cancel_goes_through_once_payments_are_refunded(): void
    {
        $order = $this->order(10);
        $payment = $this->payByCard($order);

        $this->post(route('orders.cancel', $order))->assertSessionHasErrors('order');
        $this->assertSame(OrderStatus::Confirmed, $order->refresh()->status);

        $this->post(route('orders.payments.refund', [$order, $payment]))->assertSessionHasNoErrors();
        $this->post(route('orders.cancel', $order))->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame('void', $order->invoice?->refresh()->status);
    }

    public function test_refund_and_cancel_sends_every_payment_back_the_way_it_came(): void
    {
        $order = $this->order(10);
        $this->post(route('orders.payments.store', $order), ['tender' => 'cash', 'amount' => '63.00'])->assertSessionHasNoErrors();
        $card = $this->payByCard($order);
        $this->assertSame(5_000, $card->amount_cents);

        $this->post(route('orders.refund-and-cancel', $order), ['reason' => 'Job cancelled'])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(0, $order->amount_paid_cents);
        $this->assertSame([5_000], array_column($this->cardRefunds, 'cents'));

        // The card first, since it can be refused; then the cash.
        $this->assertSame(
            [[PaymentTender::StripeCheckout, 5_000], [PaymentTender::Cash, 6_300]],
            Refund::query()->orderBy('id')->get()->map(fn (Refund $r) => [$r->tender, $r->amount_cents])->all(),
        );
        $this->assertSame('Job cancelled', Refund::query()->firstOrFail()->reason);
    }

    public function test_refund_and_cancel_checks_the_refund_limit_first(): void
    {
        $order = $this->order(30); // $339
        $this->payByCard($order);

        $sales = $this->person(UserRole::Sales);
        $this->actingAs($sales);

        $this->post(route('orders.refund-and-cancel', $order))->assertSessionHasErrors('approver_id');
        $this->assertSame([], $this->cardRefunds);
        $this->assertSame(OrderStatus::Confirmed, $order->refresh()->status);

        $this->post(route('orders.refund-and-cancel', $order), ['approver_id' => $this->owner->id, 'approver_secret' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame($this->owner->id, Refund::query()->sole()->approved_by);
        $this->assertTrue(AuditEvent::query()->where('event', 'refund approved')->where('user_id', $sales->id)->exists());
    }

    public function test_accounts_can_refund_a_payment_but_not_cancel_and_dispatch_cant_refund(): void
    {
        $order = $this->order(10);
        $card = $this->payByCard($order);

        $this->actingAs($this->person(UserRole::Dispatcher));
        $this->post(route('orders.payments.refund', [$order, $card]), ['amount' => '10.00'])->assertForbidden();

        $this->actingAs($this->person(UserRole::Accounts));
        $this->post(route('orders.refund-and-cancel', $order))->assertForbidden();
        $this->post(route('orders.payments.refund', [$order, $card]), ['amount' => '10.00'])->assertSessionHasNoErrors();

        $this->assertSame(10_300, $order->refresh()->amount_paid_cents);
    }

    public function test_a_refused_card_refund_changes_nothing(): void
    {
        $order = $this->order(10);
        $this->post(route('orders.payments.store', $order), ['tender' => 'cash', 'amount' => '63.00'])->assertSessionHasNoErrors();
        $card = $this->payByCard($order);
        $this->refuseWith = 'Stripe couldn’t refund the card: charge disputed.';

        $this->post(route('orders.payments.refund', [$order, $card]))->assertSessionHasErrors('amount');
        $this->post(route('orders.refund-and-cancel', $order))->assertSessionHasErrors('order');

        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, $card->refresh()->refunded_cents);
        $order->refresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame(11_300, $order->amount_paid_cents);
    }

    public function test_a_credit_note_refunds_to_a_card_on_the_account(): void
    {
        $order = $this->order(20); // $226
        $card = $this->payByCard($order);
        $this->post(route('orders.fulfill', $order))->assertSessionHasNoErrors();
        $line = $order->lines()->firstOrFail();
        $this->post(route('orders.returns.store', $order), [
            'lines' => [['order_line_id' => $line->id, 'quantity' => 5, 'restock' => true]],
            'restocking_percent' => 0,
        ])->assertRedirect();
        $credit = CreditNote::query()->sole();
        $this->assertSame(5_650, $credit->total_cents);

        $this->get(route('credit-notes.show', $credit))->assertInertia(fn (Assert $page) => $page
            ->where('cards.0.id', $card->id)
            ->where('cards.0.refundable_cents', 22_600));

        $this->post(route('credit-notes.refund', $credit), ['tender' => 'stripe_checkout'])->assertSessionHasErrors('payment_id');
        $this->post(route('credit-notes.refund', $credit), ['tender' => 'stripe_checkout', 'payment_id' => $card->id])
            ->assertSessionHasNoErrors();

        $credit->refresh();
        $this->assertSame(5_650, $credit->refunded_cents);
        $this->assertSame('stripe_checkout', $credit->refund_tender);
        $this->assertSame('used', $credit->status);
        $this->assertSame([5_650], array_column($this->cardRefunds, 'cents'));
        $this->assertSame((string) $credit->id, $this->cardRefunds[0]['metadata']['yardrunner_credit_note_id']);

        // The card has less left to refund, but the order is still paid.
        $this->assertSame(5_650, $card->refresh()->refunded_cents);
        $this->assertSame(22_600, $order->refresh()->amount_paid_cents);
        $this->assertSame($credit->id, Refund::query()->sole()->credit_note_id);
    }

    public function test_a_credit_note_cant_go_to_another_accounts_card(): void
    {
        $other = Organization::factory()->withCredit(10_000_000, PaymentTerms::Net30)->create();
        $this->post(route('orders.store'), ['type' => 'sale', 'organization_id' => $other->id, 'location_id' => $this->yard->id, 'fulfillment' => 'pickup']);
        $theirs = Order::query()->latest('id')->firstOrFail();
        $this->post(route('orders.lines.store', $theirs), ['product_id' => $this->mortar->id, 'quantity' => 10])->assertSessionHasNoErrors();
        $this->post(route('orders.confirm', $theirs), ['settlement' => 'prepaid'])->assertSessionHasNoErrors();
        $card = $this->payByCard($theirs->refresh());

        $credit = CreditNote::query()->create([
            'number' => 'CR-TEST', 'kind' => 'adjustment', 'organization_id' => $this->org->id,
            'subtotal_cents' => 1_000, 'total_cents' => 1_000, 'issued_on' => now()->toDateString(),
        ]);

        $this->post(route('credit-notes.refund', $credit), ['tender' => 'stripe_checkout', 'payment_id' => $card->id])
            ->assertSessionHasErrors('payment_id');
        $this->assertSame([], $this->cardRefunds);
    }

    public function test_a_refund_made_in_stripe_is_recorded_once(): void
    {
        $order = $this->order(10);
        $card = $this->payByCard($order);

        $charge = [
            'id' => 'ch_1',
            'object' => 'charge',
            'payment_intent' => $card->stripe_payment_intent_id,
            'amount' => 11_300,
            'amount_refunded' => 3_000,
            'refunds' => ['object' => 'list', 'data' => [['id' => 're_dash_1', 'object' => 'refund', 'amount' => 3_000, 'status' => 'succeeded']]],
        ];

        $this->webhook('charge.refunded', 'acct_yard', $charge)->assertOk();
        $this->webhook('charge.refunded', 'acct_yard', $charge)->assertOk(); // Stripe retries

        $refund = Refund::query()->sole();
        $this->assertSame(Refund::SOURCE_STRIPE, $refund->source);
        $this->assertSame('re_dash_1', $refund->stripe_refund_id);
        $this->assertSame(3_000, $refund->amount_cents);
        $this->assertSame(3_000, $card->refresh()->refunded_cents);
        $this->assertSame(8_300, $order->refresh()->amount_paid_cents);
        $this->assertSame(2, WebhookDelivery::query()->where('topic', 'charge.refunded')->where('outcome', WebhookDelivery::ACCEPTED)->count());
    }

    public function test_the_webhook_for_a_refund_made_here_adds_nothing(): void
    {
        $order = $this->order(10);
        $card = $this->payByCard($order);
        $this->post(route('orders.payments.refund', [$order, $card]), ['amount' => '20.00'])->assertSessionHasNoErrors();

        $this->webhook('charge.refunded', 'acct_yard', [
            'id' => 'ch_1',
            'object' => 'charge',
            'payment_intent' => $card->stripe_payment_intent_id,
            'amount_refunded' => 2_000,
            'refunds' => ['object' => 'list', 'data' => [['id' => 're_test_1', 'object' => 'refund', 'amount' => 2_000, 'status' => 'succeeded']]],
        ])->assertOk();

        $this->assertSame(1, Refund::query()->count());
        $this->assertSame(9_300, $order->refresh()->amount_paid_cents);
    }

    public function test_a_card_refund_that_fails_later_is_owed_again(): void
    {
        $order = $this->order(10);
        $card = $this->payByCard($order);
        $this->post(route('orders.payments.refund', [$order, $card]))->assertSessionHasNoErrors();
        $this->assertSame(0, $order->refresh()->amount_paid_cents);

        $failed = ['id' => 're_test_1', 'object' => 'refund', 'amount' => 11_300, 'status' => 'failed', 'failure_reason' => 'expired_or_canceled_card'];
        $this->webhook('charge.refund.updated', 'acct_yard', $failed)->assertOk();
        $this->webhook('charge.refund.updated', 'acct_yard', $failed)->assertOk();

        $refund = Refund::query()->sole();
        $this->assertSame(PaymentState::Failed, $refund->status);
        $this->assertStringContainsString('expired_or_canceled_card', (string) $refund->failure_message);
        $this->assertSame(0, $card->refresh()->refunded_cents);
        $this->assertSame(11_300, $order->refresh()->amount_paid_cents);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
    }

    public function test_refund_webhooks_from_another_account_or_for_unknown_payments_are_ignored(): void
    {
        $order = $this->order(10);
        $card = $this->payByCard($order);
        $charge = ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => $card->stripe_payment_intent_id, 'amount_refunded' => 11_300];

        $this->webhook('charge.refunded', 'acct_someone_else', $charge)->assertOk();
        $this->webhook('charge.refunded', 'acct_yard', [...$charge, 'payment_intent' => 'pi_unknown'])->assertOk();

        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(11_300, $order->refresh()->amount_paid_cents);
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function webhook(string $type, string $account, array $object): TestResponse
    {
        $payload = (string) json_encode([
            'id' => 'evt_'.uniqid(),
            'object' => 'event',
            'type' => $type,
            'account' => $account,
            'created' => time(),
            'data' => ['object' => $object],
        ]);
        $time = time();
        $signature = hash_hmac('sha256', "{$time}.{$payload}", self::CONNECT_SECRET);

        return $this->call('POST', route('webhooks.stripe'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$time},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }
}
