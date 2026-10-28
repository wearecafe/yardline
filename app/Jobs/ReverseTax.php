<?php

namespace App\Jobs;

use App\Audit\Audit;
use App\Models\CreditNote;
use App\Models\Order;
use App\Tax\ReverseSalesTax;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reverses a cancelled order's tax, or a credit note's share of it, with
 * the tax provider. Retried for about a day; a reversal that never goes
 * through is written to the audit log.
 */
class ReverseTax implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300, 1800, 3600, 7200, 21600];

    /**
     * @param  'order'|'credit_note'  $kind
     */
    public function __construct(public string $kind, public int $id) {}

    public function handle(ReverseSalesTax $reverse): void
    {
        $model = $this->model();

        match (true) {
            $model instanceof Order => $reverse->order($model),
            $model instanceof CreditNote => $reverse->creditNote($model),
            default => null,
        };
    }

    public function failed(Throwable $e): void
    {
        $model = $this->model();

        if ($model !== null) {
            Audit::record($model, 'tax reversal failed', note: 'Stripe Tax wasn’t told: '.$e->getMessage());
        }
    }

    private function model(): Order|CreditNote|null
    {
        return $this->kind === 'order'
            ? Order::query()->find($this->id)
            : CreditNote::query()->find($this->id);
    }
}
