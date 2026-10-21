<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
#[Fillable(['amount',
'period_number',
'payment_method'
])]

class Contribution extends Model
{
   public function member(){
    return $this->belongsTo(GroupMember::class, 'group_member_id');
   }
   public function group(){
    return $this->belongsTo(Group::class);
   }
   public function corrections(){
    return $this->hasMany(Correction::class);
   }
}
