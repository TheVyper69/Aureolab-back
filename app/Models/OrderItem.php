<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_id',
        'product_id',
        'variant_id',
        'qty',
        'unit_price',
        'line_total',
        'axis',
        'item_notes',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}