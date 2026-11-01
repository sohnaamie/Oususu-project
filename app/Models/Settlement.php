<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Settlement extends Model
{
    public function lines()
{
    return $this->hasMany(SettlementLine::class);
}
}
