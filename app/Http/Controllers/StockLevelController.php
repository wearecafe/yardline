<?php

namespace App\Http\Controllers;

use App\Models\StockLevel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where a product lives in a yard ("Bay 4", "Row C"), for pick lists.
 */
class StockLevelController extends Controller
{
    public function update(Request $request, StockLevel $level): RedirectResponse
    {
        $data = $request->validate([
            'bin' => ['nullable', 'string', 'max:40'],
            'reorder_point' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);

        $level->update($data);

        return back();
    }
}
