<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'optica_id',
        'created_by_user_id',
        'payment_method_id',
        'payment_status',
        'process_status',
        'notes',
        'subtotal',
        'total',
    ];

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
}