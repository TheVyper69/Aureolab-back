<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'sku','name','description','category_id','sale_price','buy_price',
        'min_stock','max_stock','active'
    ];

    protected $casts = [
        'active' => 'boolean'
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function inventory()
    {
        return $this->hasOne(Inventory::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }
}

