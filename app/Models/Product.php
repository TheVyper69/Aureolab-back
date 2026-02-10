<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'sku','name','description','category_id',
        'type','brand','model','material','size',
        'buy_price','sale_price','min_stock','max_stock',
        'supplier_id',
        'image_filename','image_mime','image_blob',
        'active'
    ];

    protected $casts = [
        'buy_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'min_stock' => 'integer',
        'max_stock' => 'integer',
        'active' => 'boolean',
        'deleted_at' => 'datetime',
    ];
    protected $hidden = [
        'image_blob',
    ];


    public function category(){
        return $this->belongsTo(Category::class, 'category_id');
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

