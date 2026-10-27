<?php

namespace App\Http\Controllers;

use App\Enums\DiscountType;
use App\Models\PriceList;
use App\Models\PriceListCategoryRule;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * $ or % off everything in a category, for organizations on a price list.
 * One rule per category per list: saving a category again replaces it.
 */
class PriceListCategoryRuleController extends Controller
{
    public function store(Request $request, PriceList $priceList): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:100'],
            'discount_type' => ['required', Rule::enum(DiscountType::class)],
            'discount' => [
                'required', 'numeric', 'gt:0',
                $request->input('discount_type') === DiscountType::Percent->value ? 'max:100' : 'max:9999999',
            ],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);

        $category = trim($data['category']);
        $percent = $data['discount_type'] === DiscountType::Percent->value;

        $existing = $priceList->categoryRules()
            ->whereRaw('lower(trim(category)) = ?', [mb_strtolower($category)])
            ->first();

        ($existing ?? new PriceListCategoryRule(['price_list_id' => $priceList->id]))->fill([
            'category' => $category,
            'discount_type' => $data['discount_type'],
            'discount_percent' => $percent ? $data['discount'] : null,
            'discount_cents' => $percent ? null : Money::parse($data['discount']),
            'starts_on' => $data['starts_on'] ?? null,
            'ends_on' => $data['ends_on'] ?? null,
        ])->save();

        return back();
    }

    public function destroy(PriceList $priceList, PriceListCategoryRule $rule): RedirectResponse
    {
        $rule->delete();

        return back();
    }
}
