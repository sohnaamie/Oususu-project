<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGroupRequest;
use App\Models\Group;
use App\Models\GroupMember;
use Illumimate\support\Facades\DB;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    public function store(storeGroupRequest $request){
        $group = DB::transaction(function () use ($request) {
        $group = new Group($request->validated());
        $group->created_by = auth()->id();
        $group->status = 'active';
        $group->save();  
        
        $member = new GroupMember(['user_id' => $request->user()->id]);
        $member ->role = 'member';  
        $group->members()->save($member);
        return $group;      
        });
        return respond ()->json($group, 201);
    }
}