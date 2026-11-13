<?php

namespace App\Http\Requests\Manage;

use App\Enums\StockMovementReason;
use App\Models\Stock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateStockRequest extends FormRequest
{
    /**
     * Reasons a manager may pick; "import" is reserved for bulk imports.
     */
    public const REASONS = [StockMovementReason::Receipt, StockMovementReason::Sale, StockMovementReason::Adjustment];

    /**
     * StockPolicy::update — the route has can:update,stock too; the request repeats
     * the check so it never relies on how the route happens to be defined.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->stock());
    }

    public function stock(): Stock
    {
        return $this->route('stock');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'reserved' => ['required', 'integer', 'min:0'],
            // Conditional: a shelf is required only while there are copies to put on it.
            'shelf_code' => [Rule::requiredIf(fn () => (int) $this->input('quantity') > 0), 'nullable', 'string', 'max:20'],
            // Rule::when(): required only when the quantity actually changes (it ends up
            // in stock_movements); moving copies to another shelf needs no reason.
            'reason' => [
                Rule::when((int) $this->input('quantity') !== $this->stock()->quantity, ['required'], ['nullable']),
                Rule::enum(StockMovementReason::class)->only(self::REASONS),
            ],
        ];
    }

    /**
     * after(): checks that need several validated fields at once. Runs after rules(),
     * so quantity/reserved are known to be integers when they have no errors.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['quantity', 'reserved'])) {
                    return;
                }

                if ((int) $this->input('reserved') > (int) $this->input('quantity')) {
                    $validator->errors()->add('reserved', __('validation.reserved_exceeds_quantity'));
                }

                // A receipt means copies arrived; it cannot explain a write-off.
                $reasonInput = $this->input('reason');
                $reason = $reasonInput instanceof StockMovementReason
                    ? $reasonInput
                    : (is_string($reasonInput) ? StockMovementReason::tryFrom($reasonInput) : null);

                if ($reason === StockMovementReason::Receipt
                    && (int) $this->input('quantity') < $this->stock()->quantity
                    && ! $validator->errors()->has('reason')) {
                    $validator->errors()->add('reason', __('validation.receipt_on_decrease'));
                }
            },
        ];
    }
}
