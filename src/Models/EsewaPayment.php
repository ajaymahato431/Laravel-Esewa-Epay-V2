<?php

namespace AjayMahato\Esewa\Models;

use Illuminate\Database\Eloquent\Model;
use AjayMahato\Esewa\Enums\PaymentStatus;

class EsewaPayment extends Model
{
    protected $table = 'esewa_payments';

    protected $fillable = [
        'transaction_uuid','product_code',
        'amount','tax_amount','service_charge','delivery_charge','total_amount',
        'status','ref_id','verified_at','raw_response','meta',
    ];

    protected $casts = [
        'verified_at'  => 'datetime',
        'raw_response' => 'array',
        'meta'         => 'array',
        'status'       => PaymentStatus::class,
    ];
}
