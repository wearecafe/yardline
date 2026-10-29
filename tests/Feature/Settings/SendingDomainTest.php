<?php

namespace Tests\Feature\Settings;

use App\Email\Domains\DnsLookup;
use App\Email\Domains\FakeDomainProvider;
use App\Email\Domains\LocalDnsZone;
use App\Email\MailGroup;
use App\Models\SendingDomain;
use App\Models\User;
use App\Notifications\SendingDomainMessage;
use App\Notifications\SendingDomainRecordsMessage;
use App\Notifications\YardMail;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A yard sends customer emails from its own domain. The flow never names
 * the email service: the yard adds CNAMEs pointing at YardRunner's zone.
 */
class SendingDomainTest extends TestCase
{
    use RefreshDatabase;

    private function connect(): SendingDomain
    {
        $this->post(route('sending-domain.store'), ['domain' => 'StoneWorks.test', 'mailbox' => 'hello'])->assertSessionHasNoErrors();

        return SendingDomain::query()->firstOrFail();
    }

    /**
     * Answers public DNS as if the yard had added every record.
     */
    private function addRecords(SendingDomain $domain): void
    {
        $answers = [];

        foreach ($domain->records as $record) {
            $answers["{$record['type']} {$record['host']}.{$domain->domain}"] = [$record['value']];
        }

        DnsLookup::fake($answers);
    }

    public function test_connecting_shows_cnames_pointing_at_yardrunner_only(): void
    {
        config(['yardrunner.email.dns_zone' => 'dns.yardrunner.test']);
        $this->signInToNewTenant();
        $tenant = CurrentTenant::get();

        $domain = $this->connect();

        $this->assertSame('stoneworks.test', $domain->domain);
        $this->assertSame(SendingDomain::PENDING, $domain->status);
        $this->assertSame(['main' => 'hello'], $domain->mailboxes);

        $cnames = collect($domain->records)->where('type', 'CNAME');
        $this->assertCount(2, $cnames);
        $this->assertSame(['yr'], $cnames->where('key', 'bounce')->pluck('host')->all(), 'our return path, not the service’s default');

        foreach ($cnames as $record) {
            $this->assertStringEndsWith(".{$tenant->id}.dns.yardrunner.test", str_replace('-', '.', $record['value']));
            // The service's real records live on our zone, under that name.
            $this->assertArrayHasKey($record['value'], LocalDnsZone::$records);
        }

        $this->get(route('sending-domain.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/sending-domain')
                ->where('domain.status', 'pending')
                ->has('domain.records', 3)
                ->missing('domain.provider_id')
                ->missing('domain.provider_records'));
    }

    public function test_free_mail_and_yardrunner_domains_are_refused(): void
    {
        $this->signInToNewTenant();

        $this->post(route('sending-domain.store'), ['domain' => 'gmail.com', 'mailbox' => 'hello'])->assertSessionHasErrors('domain');
        $this->post(route('sending-domain.store'), ['domain' => 'yard.yardrunner.ca', 'mailbox' => 'hello'])->assertSessionHasErrors('domain');
        $this->post(route('sending-domain.store'), ['domain' => 'https://stoneworks.test/', 'mailbox' => 'hello'])->assertSessionHasErrors('domain');
        $this->assertSame(0, SendingDomain::query()->count());
    }

    public function test_once_verified_customer_emails_come_from_the_yards_mailboxes(): void
    {
        Notification::fake();
        $owner = $this->signInToNewTenant();
        $tenant = CurrentTenant::get();
        $domain = $this->connect();

        // Nothing in DNS yet.
        $this->post(route('sending-domain.check'))->assertRedirect();
        $this->assertSame(SendingDomain::PENDING, $domain->refresh()->status);

        $this->patch(route('sending-domain.update'), ['mailboxes' => ['main' => 'hello'], 'sending_enabled' => true])
            ->assertSessionHasErrors('sending_enabled');

        $this->addRecords($domain);
        $this->post(route('sending-domain.check'))->assertRedirect();

        $domain->refresh();
        $this->assertSame(SendingDomain::VERIFIED, $domain->status);
        $this->assertTrue(collect($domain->records)->every('found'));
        Notification::assertSentTo($owner, SendingDomainMessage::class, fn ($n) => $n->status === SendingDomain::VERIFIED);

        $this->patch(route('sending-domain.update'), [
            'mailboxes' => ['main' => 'hello', 'accounts' => 'Accounts', 'orders' => '', 'delivery' => null, 'rentals' => 'rentals'],
            'sending_enabled' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame(['accounts@stoneworks.test', $tenant->name], YardMail::for($tenant, MailGroup::Accounts)->from);
        $this->assertSame(['hello@stoneworks.test', $tenant->name], YardMail::for($tenant, MailGroup::Orders)->from);
    }

    public function test_a_record_going_missing_falls_back_and_tells_the_yard(): void
    {
        Notification::fake();
        $owner = $this->signInToNewTenant();
        $tenant = CurrentTenant::get();
        $domain = $this->connect();
        $this->addRecords($domain);
        $this->post(route('sending-domain.check'));
        $this->patch(route('sending-domain.update'), ['mailboxes' => ['main' => 'hello'], 'sending_enabled' => true]);

        DnsLookup::fake([]);
        $this->artisan('yardrunner:email:domains')->assertSuccessful();
        // Verified domains are checked hourly.
        $this->travel(61)->minutes();
        $this->artisan('yardrunner:email:domains')->assertSuccessful();

        $this->assertSame(SendingDomain::FAILING, $domain->refresh()->status);
        $this->assertNotSame('hello@stoneworks.test', YardMail::for($tenant)->from[0]);
        Notification::assertSentTo($owner, SendingDomainMessage::class, fn ($n) => $n->status === SendingDomain::FAILING);

        // Records back: sending picks up again on its own.
        $this->addRecords($domain);
        $this->post(route('sending-domain.check'));
        $this->assertSame('hello@stoneworks.test', YardMail::for($tenant)->from[0]);
    }

    public function test_the_service_must_also_pass_the_domain(): void
    {
        $this->signInToNewTenant();
        $domain = $this->connect();
        $this->addRecords($domain);
        FakeDomainProvider::$verifies = false;

        $this->post(route('sending-domain.check'));

        $this->assertSame(SendingDomain::PARTIAL, $domain->refresh()->status);
    }

    public function test_checking_stops_after_72_hours(): void
    {
        $this->signInToNewTenant();
        $domain = $this->connect();

        $this->travel(73)->hours();
        $this->artisan('yardrunner:email:domains')->assertSuccessful();

        $this->assertSame(SendingDomain::EXPIRED, $domain->refresh()->status);

        // "Check now" starts another 72 hours.
        $this->post(route('sending-domain.check'));
        $this->assertSame(SendingDomain::PENDING, $domain->refresh()->status);
    }

    public function test_records_can_be_sent_to_whoever_runs_the_dns(): void
    {
        Notification::fake();
        $this->signInToNewTenant();
        $this->connect();

        $this->post(route('sending-domain.records'), ['email' => 'it@stoneworks.test'])->assertSessionHasNoErrors();

        Notification::assertSentOnDemand(SendingDomainRecordsMessage::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'it@stoneworks.test');
    }

    public function test_removing_the_domain_cleans_up_and_falls_back(): void
    {
        $this->signInToNewTenant();
        $domain = $this->connect();
        $names = collect($domain->records)->where('type', 'CNAME')->pluck('value');

        $this->delete(route('sending-domain.destroy'))->assertRedirect();

        $this->assertSame(0, SendingDomain::query()->count());

        foreach ($names as $name) {
            $this->assertArrayNotHasKey($name, LocalDnsZone::$records);
        }
    }

    public function test_the_records_email_never_names_the_email_service(): void
    {
        $this->signInToNewTenant();
        $domain = $this->connect();

        $html = (string) (new SendingDomainRecordsMessage($domain, 'Stoneworks Supply', 'Jen'))->toMail(new User)->render();

        $this->assertStringNotContainsStringIgnoringCase('example.net', $html, 'the service’s own hosts stay on our zone');
        $this->assertStringContainsString('dns.yardrunner', $html);
    }
}
