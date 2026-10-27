<?php

namespace Tests\Support;

use App\Accounting\QuickBooks\QuickBooksOAuth;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A small in-memory QuickBooks Online company for tests: it creates,
 * reads, updates, voids and queries entities the way the API does.
 */
class FakeQuickBooks
{
    /** @var array<string, array<string, array<string, mixed>>> entity => id => record */
    public array $records = [];

    /** @var list<array{method: string, entity: string, body: array<string, mixed>}> */
    public array $writes = [];

    /** @var list<array<string, mixed>> served by the change feed */
    public array $cdcPayments = [];

    private int $nextId = 100;

    public function __construct()
    {
        $accounts = [
            ['Id' => '1', 'Name' => 'Sales', 'AccountType' => 'Income'],
            ['Id' => '2', 'Name' => 'Delivery income', 'AccountType' => 'Income'],
            ['Id' => '3', 'Name' => 'Rental income', 'AccountType' => 'Income'],
            ['Id' => '4', 'Name' => 'Damage waiver income', 'AccountType' => 'Income'],
            ['Id' => '5', 'Name' => 'Restocking fees', 'AccountType' => 'Income'],
            ['Id' => '6', 'Name' => 'Sales discounts and allowances', 'AccountType' => 'Income'],
            ['Id' => '7', 'Name' => 'Pallet deposits held', 'AccountType' => 'Other Current Liability'],
            ['Id' => '8', 'Name' => 'Stripe clearing', 'AccountType' => 'Bank'],
            ['Id' => '9', 'Name' => 'Undeposited Funds', 'AccountType' => 'Other Current Asset'],
            ['Id' => '10', 'Name' => 'Accounts Receivable (A/R)', 'AccountType' => 'Accounts Receivable'],
        ];

        foreach ($accounts as $a) {
            $this->records['Account'][$a['Id']] = [...$a, 'FullyQualifiedName' => $a['Name']];
        }

        $this->records['TaxCode'] = [
            '20' => ['Id' => '20', 'Name' => 'HST ON', 'SalesTaxRateList' => ['TaxRateDetail' => [['TaxRateRef' => ['value' => '30']]]]],
            '21' => ['Id' => '21', 'Name' => 'GST/QST QC', 'SalesTaxRateList' => ['TaxRateDetail' => [['TaxRateRef' => ['value' => '31']], ['TaxRateRef' => ['value' => '32']]]]],
            '22' => ['Id' => '22', 'Name' => 'GST', 'SalesTaxRateList' => ['TaxRateDetail' => [['TaxRateRef' => ['value' => '31']]]]],
            '23' => ['Id' => '23', 'Name' => 'Exempt', 'SalesTaxRateList' => ['TaxRateDetail' => [['TaxRateRef' => ['value' => '33']]]]],
        ];
        $this->records['TaxRate'] = [
            '30' => ['Id' => '30', 'Name' => 'HST ON', 'RateValue' => 13],
            '31' => ['Id' => '31', 'Name' => 'GST', 'RateValue' => 5],
            '32' => ['Id' => '32', 'Name' => 'QST', 'RateValue' => 9.975],
            '33' => ['Id' => '33', 'Name' => 'Exempt', 'RateValue' => 0],
        ];
    }

    public function install(): self
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_starts_with($url, QuickBooksOAuth::TOKEN_URL)) {
                return Http::response(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600]);
            }

            $path = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $tail = preg_replace('#^/v3/company/[^/]+/#', '', $path);

            if ($tail === 'query') {
                return Http::response(['QueryResponse' => $this->query((string) $query['query'])]);
            }

            if ($tail === 'cdc') {
                return Http::response(['CDCResponse' => [['QueryResponse' => [['Payment' => $this->cdcPayments]]]]]);
            }

            [$entity, $id] = array_pad(explode('/', (string) $tail, 2), 2, null);
            $name = $this->entityName((string) $entity);

            if ($request->method() === 'GET') {
                if ($name === 'CompanyInfo') {
                    return Http::response(['CompanyInfo' => ['CompanyName' => 'Test Yard']]);
                }

                $record = $this->records[$name][$id] ?? null;

                return $record === null ? Http::response(['Fault' => ['Error' => [['Message' => 'Object Not Found', 'code' => '610']]]], 400) : Http::response([$name => $record]);
            }

            $body = $request->data();
            $this->writes[] = ['method' => ($query['operation'] ?? null) === 'void' ? 'void' : (isset($body['Id']) ? 'update' : 'create'), 'entity' => $name, 'body' => $body];

            if (($query['operation'] ?? null) === 'void') {
                $this->records[$name][$body['Id']]['voided'] = true;

                return Http::response([$name => $this->records[$name][$body['Id']]]);
            }

            if ($name === 'Customer' || $name === 'Item') {
                $field = $name === 'Customer' ? 'DisplayName' : 'Name';

                foreach ($this->records[$name] ?? [] as $existing) {
                    if (($existing[$field] ?? null) === ($body[$field] ?? null) && ($existing['Id'] ?? null) !== ($body['Id'] ?? null)) {
                        return Http::response(['Fault' => ['Error' => [['Message' => 'Duplicate Name Exists Error', 'code' => '6240']]]], 400);
                    }
                }
            }

            $id = (string) ($body['Id'] ?? $this->nextId++);
            $previous = $this->records[$name][$id] ?? [];
            $record = ($body['sparse'] ?? false) ? [...$previous, ...$body] : $body;
            $record['Id'] = $id;
            $record['SyncToken'] = (string) ((int) ($previous['SyncToken'] ?? -1) + 1);

            if (isset($record['Line'])) {
                $record['TotalAmt'] = round(array_sum(array_map(fn ($l) => (float) ($l['Amount'] ?? 0), array_filter($record['Line'], fn ($l) => ($l['DetailType'] ?? 'SalesItemLineDetail') === 'SalesItemLineDetail' || $name === 'Payment'))) + (float) ($record['TxnTaxDetail']['TotalTax'] ?? 0), 2);

                if ($name === 'Payment') {
                    $record['TotalAmt'] = (float) ($body['TotalAmt'] ?? 0);
                }
            }

            $this->records[$name][$id] = $record;

            return Http::response([$name => $record]);
        });

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function created(string $entity): array
    {
        return array_values(array_map(fn ($w) => $w['body'], array_filter($this->writes, fn ($w) => $w['entity'] === $entity && $w['method'] === 'create')));
    }

    /**
     * @return array<string, mixed>
     */
    public function last(string $entity): array
    {
        $all = array_values($this->records[$entity] ?? []);

        return $all === [] ? [] : $all[array_key_last($all)];
    }

    /**
     * @return array<string, mixed>
     */
    private function query(string $sql): array
    {
        preg_match('/from (\w+)(?: where (.*?))?(?: maxresults \d+)?$/i', $sql, $m);
        $entity = $m[1] ?? '';
        $rows = array_values($this->records[$entity] ?? []);

        if (preg_match("/(\w+) = '((?:[^'\\\\]|\\\\.)*)'/", $m[2] ?? '', $w)) {
            $value = stripslashes($w[2]);
            $rows = array_values(array_filter($rows, fn ($r) => (string) ($r[$w[1]] ?? '') === $value));
        }

        if (preg_match('/Id in \((.*)\)/', $m[2] ?? '', $in)) {
            $ids = array_map(fn ($v) => trim($v, " '"), explode(',', $in[1]));
            $rows = array_values(array_filter($rows, fn ($r) => in_array((string) $r['Id'], $ids, true)));
        }

        return $rows === [] ? [] : [$entity => $rows];
    }

    private function entityName(string $path): string
    {
        return match ($path) {
            'creditmemo' => 'CreditMemo',
            'journalentry' => 'JournalEntry',
            'companyinfo' => 'CompanyInfo',
            'taxcode' => 'TaxCode',
            'taxrate' => 'TaxRate',
            'timeactivity' => 'TimeActivity',
            default => ucfirst($path),
        };
    }
}
