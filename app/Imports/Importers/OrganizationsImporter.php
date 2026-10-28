<?php

namespace App\Imports\Importers;

use App\Enums\PaymentTerms;
use App\Imports\ImportContext;
use App\Imports\Importer;
use App\Imports\Row;
use App\Models\Customer;
use App\Models\CustomerMembership;
use App\Models\Organization;

/**
 * Contractor accounts and the people who buy for them. Rows with the same
 * account number (or, without one, the same name) are one account; each
 * row can add one contact. Blank cells leave what's already there.
 */
class OrganizationsImporter implements Importer
{
    /** @var array<string|int, PaymentTerms> */
    private const TERMS = [
        'due on receipt' => PaymentTerms::DueOnReceipt, 'due_on_receipt' => PaymentTerms::DueOnReceipt, 'on receipt' => PaymentTerms::DueOnReceipt,
        'receipt' => PaymentTerms::DueOnReceipt, 'cod' => PaymentTerms::DueOnReceipt, 'c.o.d.' => PaymentTerms::DueOnReceipt, '0' => PaymentTerms::DueOnReceipt,
        'net 15' => PaymentTerms::Net15, 'net15' => PaymentTerms::Net15, 'net_15' => PaymentTerms::Net15, 'n15' => PaymentTerms::Net15, '15' => PaymentTerms::Net15,
        'net 30' => PaymentTerms::Net30, 'net30' => PaymentTerms::Net30, 'net_30' => PaymentTerms::Net30, 'n30' => PaymentTerms::Net30, '30' => PaymentTerms::Net30,
        'net 60' => PaymentTerms::Net60, 'net60' => PaymentTerms::Net60, 'net_60' => PaymentTerms::Net60, 'n60' => PaymentTerms::Net60, '60' => PaymentTerms::Net60,
    ];

    public function check(Row $row, ImportContext $context): void
    {
        $name = $row->text('name', 255);
        $number = $row->text('account_number', 50);

        if ($name === null && $number === null) {
            $row->error('name', 'Name is required.');

            return;
        }

        $org = $this->find($context, $number, $name);
        $key = $number ?? (string) $name;
        $new = $org === null && ! $context->planned('organization', $key);

        if ($new && $name === null) {
            $row->error('name', 'Name is required for a new account.');
        }

        $termsCell = $row->text('terms', 30);
        $terms = $termsCell === null ? null : (self::TERMS[ImportContext::key($termsCell)] ?? null);

        if ($termsCell !== null && $terms === null) {
            $row->error('terms', 'Use due on receipt, net 15, net 30 or net 60.');
        }

        $priceListName = $row->text('price_list', 120);
        $priceList = $priceListName === null ? null : $context->priceList($priceListName);

        if ($priceListName !== null && $priceList === null) {
            $row->error('price_list', "There’s no price list called {$priceListName}. Import price lists first, or leave it blank.");
        }

        $email = $this->email($row, 'email');

        $row->data = [
            'key' => $key,
            'number' => $number,
            'name' => $name,
            'fields' => array_filter([
                'name' => $name,
                'account_number' => $number,
                'email' => $email,
                'phone' => $row->text('phone', 40),
                'address_line1' => $row->text('address_line1'),
                'address_line2' => $row->text('address_line2'),
                'city' => $row->text('city', 120),
                'region' => $row->text('region', 50),
                'postal_code' => $row->text('postal_code', 20),
                'payment_terms' => $terms,
                'credit_limit_cents' => $row->money('credit_limit'),
                'price_list_id' => $priceList?->id,
                'tax_exempt' => $row->bool('tax_exempt'),
                'tax_exempt_number' => $row->text('tax_exempt_number', 50),
                'notes' => $row->text('notes', 2000),
            ], fn ($value) => $value !== null),
            'contact' => $this->contact($row),
        ];

        $row->action = $new ? Row::CREATE : Row::UPDATE;

        if ($new && $row->ok()) {
            $context->plan('organization', $key);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function contact(Row $row): ?array
    {
        $columns = ['contact_first_name', 'contact_last_name', 'contact_email', 'contact_phone', 'contact_role', 'can_charge'];

        if (array_filter($columns, fn ($c) => $row->has($c)) === []) {
            return null;
        }

        $first = $row->text('contact_first_name', 100);

        if ($first === null) {
            $row->error('contact_first_name', 'A contact needs a first name.');
        }

        $role = ImportContext::key($row->text('contact_role', 20) ?? 'buyer');
        $role = $role === 'account admin' ? 'admin' : $role;

        if (! array_key_exists($role, CustomerMembership::ROLES)) {
            $row->error('contact_role', 'Use buyer, approver or admin.');
        }

        return [
            'first_name' => $first,
            'last_name' => $row->text('contact_last_name', 100),
            'email' => $this->email($row, 'contact_email'),
            'phone' => $row->text('contact_phone', 40),
            'role' => $role,
            'can_charge_account' => $row->bool('can_charge', true),
        ];
    }

    private function email(Row $row, string $column): ?string
    {
        $email = $row->text($column, 255);

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $row->error($column, "{$email} isn’t an email address.");

            return null;
        }

        return $email === null ? null : mb_strtolower($email);
    }

    private function find(ImportContext $context, ?string $number, ?string $name): ?Organization
    {
        $org = $number !== null
            ? Organization::query()->whereRaw('lower(account_number) = ?', [ImportContext::key($number)])->first()
            : null;

        return $org ?? ($name !== null
            ? Organization::query()->whereRaw('lower(name) = ?', [ImportContext::key($name)])->first()
            : null);
    }

    public function apply(Row $row, ImportContext $context): string
    {
        $data = $row->data;
        $org = $this->find($context, $data['number'], $data['name']);
        $action = $org === null ? Row::CREATE : Row::UPDATE;

        if ($org === null) {
            $org = Organization::query()->create($data['fields']);
        } else {
            $org->fill($data['fields'])->save();
        }

        if ($data['contact'] !== null) {
            $this->saveContact($org, $data['contact']);
        }

        return $action;
    }

    /**
     * @param  array<string, mixed>  $contact
     */
    private function saveContact(Organization $org, array $contact): void
    {
        $person = $contact['email'] !== null
            ? Customer::query()->whereRaw('lower(email) = ?', [$contact['email']])->first()
            : null;

        $person ??= Customer::query()
            ->where('organization_id', $org->id)
            ->whereRaw('lower(first_name) = ?', [ImportContext::key($contact['first_name'])])
            ->whereRaw("lower(coalesce(last_name, '')) = ?", [ImportContext::key((string) $contact['last_name'])])
            ->first();

        $fields = array_filter([
            'first_name' => $contact['first_name'],
            'last_name' => $contact['last_name'],
            'email' => $contact['email'],
            'phone' => $contact['phone'],
        ], fn ($value) => $value !== null);

        if ($person === null) {
            $person = Customer::query()->create(['organization_id' => $org->id, ...$fields]);
        } else {
            $person->fill([...$fields, 'organization_id' => $person->organization_id ?? $org->id])->save();
        }

        $org->contacts()->syncWithoutDetaching([
            $person->id => [
                'tenant_id' => $org->tenant_id,
                'role' => $contact['role'],
                'can_charge_account' => (bool) $contact['can_charge_account'],
            ],
        ]);
    }
}
