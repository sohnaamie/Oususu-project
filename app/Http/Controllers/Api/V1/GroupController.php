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
}
