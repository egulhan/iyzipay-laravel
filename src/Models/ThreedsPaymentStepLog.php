<?php

namespace Iyzico\IyzipayLaravel\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThreedsPaymentStepLog extends Model
{
    const OPTION_NO = 0;
    const OPTION_YES = 1;
    // step options
    const STEP_INIT = 1;
    const STEP_REQUESTED_CALLBACK_URL = 2;
    const STEP_MAKE_THREEDS_PAYMENT = 3;

    protected $fillable = [
        'payment_log_id',
        'result',
        'error_message',
        'step',
        'payload',
        'response',
    ];

    protected $casts = [
        'payload' => 'array',
        'response' => 'array',
    ];

    public function paymentLog(): BelongsTo
    {
        return $this->belongsTo(PaymentLog::class);
    }
}
