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

    public function currentPeriod(): int
{
    $start = \Carbon\Carbon::parse($this->start_date);

    if (now()->lt($start)) {
        return 0; // not started yet
    }

    $elapsed = $this->frequency === 'weekly'
        ? $start->diffInWeeks(now())
        : $start->diffInMonths(now());

    return min((int) floor($elapsed) + 1, $this->total_payments);
}
}
