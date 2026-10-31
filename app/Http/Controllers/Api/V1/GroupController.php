<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddMemberRequest;
use App\Http\Requests\StoreContributionRequest;
use App\Http\Requests\StoreCorrectionRequest;
use App\Http\Requests\StoreGroupRequest;
use App\Models\Contribution;
use App\Models\Group;
use App\Models\User;
use App\Services\GroupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use App\Http\Requests\StorePayoutRequest;
use App\Models\Correction;
use App\Http\Requests\SettleGroupRequest;

class GroupController extends Controller
{
    public function __construct(private readonly GroupService $groupService) {}

    public function store(StoreGroupRequest $request)
    {
        $result = $this->groupService->create($request->validated(), $request->user());

        return response()->json($result['data'], $result['status']);
    }

    public function index(Request $request)
    {
        return response()->json($this->groupService->groupsFor($request->user()));
    }

    public function show(Request $request, Group $group)
    {
        Gate::authorize('view', $group);

        return response()->json($group);
    }

    public function members(Group $group)
    {
        Gate::authorize('view', $group);

        return response()->json($this->groupService->members($group));
    }

    public function addMember(AddMemberRequest $request, Group $group)
    {
        Gate::authorize('addMember', $group);

        $data = $request->validated();
        $user = User::where('email', $data['email'])->firstOrFail();
        $result = $this->groupService->addMember($group, $user, $data);

        return response()->json($result['data'], $result['status']);
    }

    public function storeContribution(StoreContributionRequest $request, Group $group)
    {
        Gate::authorize('recordContribution', $group);
        $result = $this->groupService->storeContribution(
            $group,
            $request->validated(),
            $request->user()
        );

        return response()->json($result['data'], $result['status']);
    }

    public function balance(Group $group)
    {
        Gate::authorize('view', $group);

        return response()->json($this->groupService->balance($group));
    }

    public function storeCorrection(StoreCorrectionRequest $request, Group $group, Contribution $contribution)
    {
        Gate::authorize('recordCorrection', $group);
        $result = $this->groupService->storeCorrection(
            $group,
            $contribution,
            $request->validated(),
            $request->user()
        );

        return response()->json($result['data'], $result['status']);
    }

    public function start(Group $group)
    {
        Gate::authorize('start', $group);
        $result = $this->groupService->start($group);

        return response()->json($result['data'], $result['status']);
    }

    public function storePayout(StorePayoutRequest $request, Group $group, GroupService $service)
{
    Gate::authorize('recordPayout', $group);

    [$body, $status] = $service->recordPayout(
        $group,
        $request->validated()['period_number'],
        $request->user()->id
    );

    return response()->json($body, $status);
}

public function behind(Group $group)
{
    Gate::authorize('viewArrears', $group);

    $paid = Contribution::where('group_id', $group->id)
        ->groupBy('group_member_id')
        ->selectRaw('group_member_id, SUM(amount) as total')
        ->pluck('total', 'group_member_id');

    $reversed = Correction::join('contributions', 'corrections.contribution_id', '=', 'contributions.id')
        ->where('contributions.group_id', $group->id)
        ->groupBy('contributions.group_member_id')
        ->selectRaw('contributions.group_member_id as gm, SUM(corrections.amount) as total')
        ->pluck('total', 'gm');

    $target = (int) $group->target_amount;

    $rows = $group->members()->with('user:id,name')->get()->map(function ($m) use ($paid, $reversed, $target) {
        $net = (int) ($paid[$m->id] ?? 0) - (int) ($reversed[$m->id] ?? 0);

        return [
            'member_id' => $m->id,
            'name' => $m->user->name,
            'net_paid' => $net,
            'target' => $target,
            'short_by' => max($target - $net, 0),
            'reached_target' => $net >= $target,
        ];
    });

    return response()->json($rows->where('reached_target', false)->values());
}

public function settle(SettleGroupRequest $request, Group $group, GroupService $service)
{
    Gate::authorize('settle', $group);

    [$body, $status] = $service->settle($group, $request->validated()['spent'], $request->user()->id);

    return response()->json($body, $status);
}
}
