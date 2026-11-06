<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A read-only SQL union over the existing finance records, never persisted.
 * @property int $id
 * @property string $source_id
 * @property string $kind
 * @property string $payment_id
 * @property string|null $reverses_id
 * @property string $currency
 * @property string|null $effective_on
 * @property int $amount
 * @property int $fee_amount
 * @property int $unattributed_refund_amount
 */
class AccountingCashRow extends Model
{
    protected $table = 'accounting_cash';

    protected function casts(): array
    {
        return ['id' => 'integer', 'amount' => 'integer', 'fee_amount' => 'integer', 'unattributed_refund_amount' => 'integer'];
    }
}
