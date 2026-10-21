<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
#[Fillable(['amount', 'reason'])]
class Correction extends Model
{
  public function contribution(){
        return $this->belongsTo(Contribution::class);
    }
}
