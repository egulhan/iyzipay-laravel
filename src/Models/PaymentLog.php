<?php

namespace Iyzico\IyzipayLaravel\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentLog extends Model
{
    const OPTION_NO = 0;
    const OPTION_YES = 1;

    protected $fillable = [
        'result',
        'error_message',
        'billable_id',
        'is_threeds',
        'credit_card_id',
        'credit_card_number',
        'subscription_id',
        'amount',
        'currency',
        'products',
        'iyzipay_key',
        'response',
    ];

    protected $casts = [
        'products' => 'array',
        'response' => 'array',
    ];

    public function billable(): BelongsTo
    {
        return $this->belongsTo(config('iyzipay.billableModel'), 'billable_id');
    }

    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function threedsPaymentStepLogs(): HasMany
    {
        return $this->hasMany(ThreedsPaymentStepLog::class);
    }

    public function addThreedPaymentStepLog($threedsPaymentStepLog)
    {
        $method = is_array($threedsPaymentStepLog) ? 'saveMany' : 'save';
        $this->threedsPaymentStepLogs()->$method($threedsPaymentStepLog);

        return $threedsPaymentStepLog;
    }
}
