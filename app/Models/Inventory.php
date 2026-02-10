<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Inventory extends Model
{
    protected $table = 'inventory'; 
    protected $fillable = ['product_id','stock','last_entry_date'];

    public function product(){
        return $this->belongsTo(Product::class, 'product_id');
    }
}

