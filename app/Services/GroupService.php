<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\Correction;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Turn;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Exception\UniqueConstraintViolationException;

class GroupService
{
    /**
     * @return array{data: mixed, status: int}
     */
    public function create(array $data, User $user): array
    {
        $group = DB::transaction(function () use ($data, $user) {
            $group = new Group($data);
            $group->created_by = $user->id;
            $group->status = 'forming';
            $group->save();

            $member = new GroupMember(['user_id' => $user->id]);
            $member->role = 'admin';
            $group->members()->save($member);

            return $group;
        });

        return ['data' => $group, 'status' => 201];
    }

    public function groupsFor(User $user): Collection
    {
        return $user->groups()->get();
    }

    public function members(Group $group): Collection
    {
        return $group->members()->with('user:id,name')->get();
    }

    /**
     * @return array{data: mixed, status: int}
     */
    public function start(Group $group): array
    {
        return DB::transaction(function () use ($group) {
            $group = Group::lockForUpdate()->findOrFail($group->id);

            if ($group->status !== 'forming') {
                return [
                    'data' => ['message' => 'This group has already started.'],
                    'status' => 409,
                ];
            }

            $members = $group->members()->orderBy('id')->get();

            if ($members->count() < 2) {
                return [
                    'data' => ['message' => 'A group needs at least 2 members to start.'],
                    'status' => 422,
                ];
            }

            if ($group->payout_type === 'rotating') {
                $slots = $members
                    ->flatMap(fn ($m) => array_fill(0, $m->number_of_shares, $m))
                    ->shuffle()
                    ->values();

                foreach ($slots as $index => $m) {
                    $turn = new Turn;
                    $turn->group_id = $group->id;
                    $turn->group_member_id = $m->id;
                    $turn->turn_number = $index + 1;
                    $turn->save();
                }
            }

            $group->status = 'active';
            $group->save();

            return ['data' => $group, 'status' => 200];
        });
    }

    /**
     * @return array{data: mixed, status: int}
     */
    public function addMember(Group $group, User $user, array $data): array
    {
        return DB::transaction(function () use ($group, $user, $data) {
            $group = Group::lockForUpdate()->findOrFail($group->id);
            $this->ensureTurnsCover($group);

            if ($group->members()->where('user_id', $user->id)->exists()) {
                return [
                    'data' => ['message' => 'User is already a member of the group.'],
                    'status' => 409,
                ];
            }

            $member = new GroupMember([
                'user_id' => $user->id,
                'number_of_shares' => $data['number_of_shares'] ?? 1,
            ]);
            $member->role = 'member';
            $member->joined_at = now();

            $openEndedRotatingGroup = $group->payout_type === 'rotating'
                && $group->status === 'active'
                && $group->total_payments === null;

            if ($group->payout_type === 'rotating'
                && $group->status === 'active'
                && ! $openEndedRotatingGroup) {
                $from = $group->currentPeriod() + 1;
                $taken = $group->members()->whereNotNull('turn_order')->pluck('turn_order');

                $free = $from > $group->total_payments
                    ? collect()
                    : collect(range($from, $group->total_payments))->diff($taken);

                if ($free->isEmpty()) {
                    return [
                        'data' => ['message' => 'No payout turns are left in this group.'],
                        'status' => 422,
                    ];
                }

                $member->turn_order = $free->random();
            }

            try {
                $group->members()->save($member);
            } catch (UniqueConstraintViolationException) {
                return [
                    'data' => ['message' => 'User is already a member of the group.'],
                    'status' => 409,
                ];
            }

            if ($group->payout_type === 'rotating' && $group->status === 'active') {
                $this->addTurnsFor($group, $member);
            }

            return ['data' => $member, 'status' => 201];
        });
    }

    /**
     * @return array{data: mixed, status: int}
     */
    public function storeContribution(Group $group, array $data, User $user): array
    {
        if ($group->status !== 'active') {
            return [
                'data' => ['message' => 'This group has not started yet.'],
                'status' => 409,
            ];
        }

        return DB::transaction(function () use ($group, $data, $user) {
            $member = GroupMember::lockForUpdate()->findOrFail($data['group_member_id']);
            $expected = $group->contribution_amount * $member->number_of_shares;
            $ids = Contribution::where('group_member_id', $member->id)
                ->where('period_number', $data['period_number'])
                ->pluck('id');

            $paid = (int) Contribution::whereIn('id', $ids)->sum('amount');
            $reversed = (int) Correction::whereIn('contribution_id', $ids)->sum('amount');
            $remaining = $expected - ($paid - $reversed);

            if ($data['amount'] > $remaining) {
                return [
                    'data' => [
                        'message' => 'This payment is more than what is still owed.',
                        'remaining' => max($remaining, 0),
                    ],
                    'status' => 409,
                ];
            }

            $contribution = new Contribution($data);
            $contribution->group_id = $group->id;
            $contribution->group_member_id = $member->id;
            $contribution->recorded_by = $user->id;
            $contribution->paid_at = now();
            $contribution->save();

            return ['data' => $contribution, 'status' => 201];
        });
    }

    public function balance(Group $group): Collection
    {
        $reversedByMember = Correction::join(
            'contributions',
            'corrections.contribution_id',
            '=',
            'contributions.id'
        )
            ->where('contributions.group_id', $group->id)
            ->groupBy('contributions.group_member_id')
            ->selectRaw('contributions.group_member_id, SUM(corrections.amount) as total')
            ->pluck('total', 'group_member_id');

        $periods = $group->total_payments ?? $group->currentPeriod();

        return $group->members()
            ->with('user:id,name')
            ->withSum('contributions as paid', 'amount')
            ->get()
            ->map(function (GroupMember $member) use ($group, $reversedByMember, $periods) {
                $paid = (int) $member->paid;
                $reversed = (int) ($reversedByMember[$member->id] ?? 0);

                return [
                    'member_id' => $member->id,
                    'name' => $member->user->name,
                    'paid' => $paid,
                    'reversed' => $reversed,
                    'balance' => $paid - $reversed,
                    'expected' => $group->contribution_amount
                        * $periods
                        * $member->number_of_shares,
                ];
            });
    }

    /**
     * @return array{data: mixed, status: int}
     */
    public function storeCorrection(
        Group $group,
        Contribution $contribution,
        array $data,
        User $user
    ): array {
        if ($contribution->group_id !== $group->id) {
            return [
                'data' => ['message' => 'Contribution does not belong to this group.'],
                'status' => 404,
            ];
        }

        return DB::transaction(function () use ($contribution, $data, $user) {
            $locked = Contribution::lockForUpdate()->findOrFail($contribution->id);

            $alreadyReversed = (int) $locked->corrections()->sum('amount');
            $remaining = $locked->amount - $alreadyReversed;

            if ($data['amount'] > $remaining) {
                return [
                    'data' => [
                        'message' => 'Correction is larger than the amount still reversible.',
                        'remaining' => $remaining,
                    ],
                    'status' => 422,
                ];
            }

            $correction = new Correction($data);
            $correction->contribution_id = $locked->id;
            $correction->recorded_by = $user->id;
            $correction->save();

            return ['data' => $correction, 'status' => 201];
        });
    }

    private function addTurnsFor(Group $group, GroupMember $member): void
    {
        $fixedUntil = $group->currentPeriod();
        $next = max((int) $group->turns()->max('turn_number'), $fixedUntil);

        // Create one new turn row per share at the end.
        for ($i = 0; $i < $member->number_of_shares; $i++) {
            $turn = new Turn;
            $turn->group_id = $group->id;
            $turn->group_member_id = $member->id;
            $turn->turn_number = ++$next;
            $turn->save();
        }

        $future = $group->turns()
            ->where('turn_number', '>', $fixedUntil)
            ->orderBy('turn_number')
            ->get();

        $order = $future->pluck('group_member_id')
            ->reject(fn ($id) => $id === $member->id)
            ->values()
            ->all();

        for ($i = 0; $i < $member->number_of_shares; $i++) {
            array_splice($order, random_int(0, count($order)), 0, [$member->id]);
        }

        foreach ($future as $index => $turn) {
            $turn->group_member_id = $order[$index];
            $turn->save();
        }
    }

    public function ensureTurnsCover(Group $group): void
    {
        if ($group->payout_type !== 'rotating' || $group->status !== 'active') {
            return;
        }

        $members = $group->members()->get();

        // keep building rounds until there is at least one future turn
        while ((int) $group->turns()->max('turn_number') <= $group->currentPeriod()) {
            $slots = $members
                ->flatMap(fn ($m) => array_fill(0, $m->number_of_shares, $m))
                ->shuffle()
                ->values();

            if ($slots->isEmpty()) {
                break;
            }

            $round = (int) $group->turns()->max('round') + 1;
            $next = (int) $group->turns()->max('turn_number');

            foreach ($slots as $m) {
                $turn = new Turn;
                $turn->group_id = $group->id;
                $turn->group_member_id = $m->id;
                $turn->turn_number = ++$next;
                $turn->round = $round;
                $turn->save();
            }
        }
    }
}
