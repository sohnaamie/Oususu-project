<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(
    'name',
    'contribution_amount',
    'frequency',
    'total_payments',
    'start_date',
    'payout_type'
)]
class Group extends Model
{
    public function members(){
        return $this->hasMany(GroupMember::class);
    }
    public function creator(){
        return $this->belongsTo(User::class, 'created_by');
    }
}
