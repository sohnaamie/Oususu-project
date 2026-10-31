<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(
    'group_id',
    'user_id',
    'number_of_shares',
    'joined_at'
)]
class GroupMember extends Model
{

    public function group(){
        return $this->belongsTo(Group::class);
    }
    public function user(){
        return $this->belongsTo(User::class);
    }
    public function contributions(){
        return $this->hasMany(Contribution::class, 'group_member_id');
    }

    public function turns() {
         return $this->hasMany(Turn::class);
          }
}
