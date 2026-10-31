<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payout extends Model
{
    public function group() { return $this->belongsTo(Group::class); }

    public function member() { return $this->belongsTo(GroupMember::class, 'group_member_id'); }
}