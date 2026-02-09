<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id','variant_type',
        'sph','cyl','add_power','bc','dia','color',
        'active'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function inventory()
    {
        return $this->hasOne(InventoryVariant::class, 'variant_id');
    }
}

