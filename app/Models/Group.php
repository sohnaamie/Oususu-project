<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(
    'name',
    'contribution_amount',
    'frequency',
    'total_payments',
    'start_date',
    'payout_type',
    'target_amount'
)]
class Group extends Model
{
    public function members()
    {
        return $this->hasMany(GroupMember::class);
    }

    public function contributions()
    {
        return $this->hasMany(Contribution::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function currentPeriod(): int
    {
        $start = Carbon::parse($this->start_date);

        if (now()->lt($start)) {
            return 0; // not started yet
        }

        $elapsed = $this->frequency === 'weekly'
            ? $start->diffInWeeks(now())
            : $start->diffInMonths(now());

        $period = (int) floor($elapsed) + 1;

        return $this->total_payments ? min($period, $this->total_payments) : $period;
    }

    public function turns()
    {
        return $this->hasMany(Turn::class);
    }

    public function payouts()
    {
        return $this->hasMany(Payout::class);
    }
}
