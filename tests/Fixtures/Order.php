<?php

namespace AjayMahato\Esewa\Tests\Fixtures;

use AjayMahato\Esewa\Concerns\HasEsewaPayments;
use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for whatever an application charges for.
 */
class Order extends Model
{
    use HasEsewaPayments;

    protected $table = 'orders';

    protected $fillable = ['reference'];
}
