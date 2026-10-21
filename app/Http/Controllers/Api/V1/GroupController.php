<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGroupRequest;
use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use App\Http\Requests\AddMemberRequest;
use App\Models\User;


class GroupController extends Controller
{
    public function store(StoreGroupRequest $request){
        $group = DB::transaction(function () use ($request) {
        $group = new Group($request->validated());
        $group->created_by = $request->user()->id;
        $group->status = 'forming';
        $group->save();  
        
        $member = new GroupMember(['user_id' => $request->user()->id]);
        $member ->role = 'admin';  
        $group->members()->save($member);
        return $group;      
        });
        return response()->json($group, 201);
    }
    public function index(Request $request){
        $groups = $request->user()->groups()->get();
        return response()->json($groups);
        
    }
    public function show(Request $request, Group $group){
       Gate::authorize('view', $group);
        return response()->json($group);
    }
    public function members(Group $group){
        Gate::authorize('view', $group);
       return response()->json(
        $group->members()->with('user:id,name')->get());
    }

    public function addMember(AddMemberRequest $request, Group $group)
{
    Gate::authorize('addMember', $group);
    $data = $request->validated();
    
    $user = User::where('email', $request->validated()['email'])->firstOrFail();
    if ($group->members()->where('user_id', $user->id)->exists()) {
        return response()->json(['message' => 'User is already a member of the group.'], 409);
    }
    $member = new GroupMember([
        'user_id' => $user->id,
        'number_of_shares' => $request->validated()['number_of_shares'] ?? 1,
    ]);
    $member->role = 'member';
    $group->members()->save($member);

    return response()->json($member, 201);
}


}