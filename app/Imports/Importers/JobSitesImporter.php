<?php

namespace App\Imports\Importers;

use App\Imports\ImportContext;
use App\Imports\Importer;
use App\Imports\Row;
use App\Models\Organization;
use App\Models\OrganizationLocation;

/**
 * Where each account takes deliveries. Sites are matched by account and
 * name; blank cells leave what's already there.
 */
class JobSitesImporter implements Importer
{
    public function check(Row $row, ImportContext $context): void
    {
        $account = $row->text('account', 255, required: true);
        $name = $row->text('name', 120, required: true, label: 'site name');
        $org = $account === null ? null : $context->organization($account);

        if ($account !== null && $org === null) {
            $row->error('account', "There’s no account {$account}. Import accounts first.");
        }

        $yardCell = $row->text('yard', 120);
        $yard = $yardCell === null ? null : $context->yard($yardCell);

        if ($yardCell !== null && $yard === null) {
            $row->error('yard', "There’s no yard called {$yardCell}.");
        }

        if ($org === null || $name === null) {
            return;
        }

        if ($context->planned('site', "{$org->id}|{$name}")) {
            $row->error('name', "{$org->name} already has {$name} on a row above.");

            return;
        }

        $context->plan('site', "{$org->id}|{$name}");
        $site = $this->find($org, $name);

        $row->data = [
            'organization_id' => $org->id,
            'name' => $name,
            'fields' => array_filter([
                'address_line1' => $row->text('address_line1', 255, required: $site === null, label: 'address'),
                'address_line2' => $row->text('address_line2'),
                'city' => $row->text('city', 120),
                'region' => $row->text('region', 50),
                'postal_code' => $row->text('postal_code', 20),
                'location_id' => $yard?->id,
                'po_required' => $row->bool('po_required'),
                'delivery_notes' => $row->text('delivery_notes', 2000),
                'is_default' => $row->bool('default'),
            ], fn ($value) => $value !== null),
        ];
        $row->action = $site === null ? Row::CREATE : Row::UPDATE;
    }

    public function apply(Row $row, ImportContext $context): string
    {
        $data = $row->data;
        $org = Organization::query()->findOrFail((int) $data['organization_id']);
        $site = $this->find($org, $data['name']);
        $action = $site === null ? Row::CREATE : Row::UPDATE;

        $site ??= new OrganizationLocation(['organization_id' => $org->id, 'name' => $data['name']]);
        $site->fill($data['fields'])->save();

        // One main site per account.
        if ($site->is_default) {
            $org->locations()->whereKeyNot($site->id)->update(['is_default' => false]);
        }

        return $action;
    }

    private function find(Organization $org, string $name): ?OrganizationLocation
    {
        return $org->locations()->whereRaw('lower(name) = ?', [ImportContext::key($name)])->first();
    }
}
