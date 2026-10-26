<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class LocationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('locations/index', [
            'locations' => Location::query()->orderByDesc('is_default')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->save(new Location, $this->validated($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Yard added.']);

        return back();
    }

    public function update(Request $request, Location $location): RedirectResponse
    {
        $this->save($location, $this->validated($request, $location));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Yard saved.']);

        return back();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(Location $location, array $data): void
    {
        DB::transaction(function () use ($location, $data) {
            $isFirst = Location::query()->doesntExist();
            $location->fill($data);
            $location->is_default = $isFirst || ($data['is_default'] ?? false) || $location->is_default;
            $location->save();

            if ($location->is_default) {
                Location::query()->whereKeyNot($location->id)->update(['is_default' => false]);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Location $location = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20', TenantRule::unique('locations', 'code', $location?->id)],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:50'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['required', 'string', 'size:2'],
            'is_default' => ['boolean'],
        ]);

        return [...$data, 'code' => strtoupper($data['code']), 'country' => strtoupper($data['country'])];
    }
}
