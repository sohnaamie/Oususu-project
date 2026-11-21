<?php

namespace Tests\Feature;

use App\Models\GroupMember;
use App\Models\Payout;
use App\Models\Turn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use App\Models\Group;


class GroupFlowTest extends TestCase
{
    use RefreshDatabase;

    private function groupBody(array $override = []): array
    {
        return array_merge([
            'name' => 'Test Osusu',
            'contribution_amount' => 50,
            'frequency' => 'weekly',
            'total_payments' => 4,
            'start_date' => now()->toDateString(),
            'payout_type' => 'rotating',
        ], $override);
    }

    private function lumpSumBody(array $o = []): array
    {
        return array_merge($this->groupBody([
            'payout_type' => 'lump_sum',
            'total_payments' => 4,
            'target_amount' => 50,
            'contribution_amount' => 50,
        ]), $o);
    }

    private function pay(int $groupId, int $memberId, int $amount, int $period = 1): void
    {
        $this->postJson("/api/v1/groups/$groupId/contributions", [
            'group_member_id' => $memberId,
            'period_number' => $period,
            'amount' => $amount,
            'payment_method' => 'cash',
        ])->assertCreated();
    }

    public function test_creating_a_group_makes_creator_admin(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/groups', $this->groupBody())->assertCreated();

        $this->assertDatabaseHas('group_members', [
            'user_id' => $user->id,
            'role' => 'admin',
        ]);
    }

    public function test_client_cannot_set_created_by_or_status(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/groups', $this->groupBody([
            'created_by' => $other->id,
            'status' => 'active',
        ]))->assertCreated();

        $this->assertDatabaseHas('groups', [
            'created_by' => $user->id,
            'status' => 'forming',
        ]);
    }

    public function test_outsider_gets_404_and_plain_member_gets_403(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();

        Sanctum::actingAs($admin);
        $groupId = $this->postJson('/api/v1/groups', $this->groupBody())->json('id');

        $this->postJson("/api/v1/groups/$groupId/members", ['email' => $member->email])
            ->assertCreated();

        Sanctum::actingAs($outsider);
        $this->getJson("/api/v1/groups/$groupId")->assertNotFound();

        Sanctum::actingAs($member);
        $this->postJson("/api/v1/groups/$groupId/members", ['email' => $outsider->email])
            ->assertForbidden();
    }

    public function test_start_assigns_unique_turns_and_late_joiners_run_out(): void
    {
        $admin = User::factory()->create();
        $users = User::factory()->count(4)->create();

        Sanctum::actingAs($admin);
        $groupId = $this->postJson('/api/v1/groups', $this->groupBody([
            'total_payments' => null,
        ]))->json('id');

        $this->postJson("/api/v1/groups/$groupId/members", ['email' => $users[0]->email])->assertCreated();
        $this->postJson("/api/v1/groups/$groupId/members", ['email' => $users[1]->email])->assertCreated();
        $this->postJson("/api/v1/groups/$groupId/start")->assertOk();

        $this->assertDatabaseCount('turns', 3);

        $this->postJson("/api/v1/groups/$groupId/members", ['email' => $users[2]->email])->assertCreated();
        $this->postJson("/api/v1/groups/$groupId/members", ['email' => $users[3]->email])->assertCreated();

        $turns = Turn::where('group_id', $groupId)->orderBy('turn_number')->pluck('turn_number')->all();
        $this->assertEquals(range(1, count($turns)), $turns); // no gaps, no repeats
    }

    public function test_payment_corrections_and_overpayment(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();

        Sanctum::actingAs($admin);
        $groupId = $this->postJson('/api/v1/groups', $this->groupBody())->json('id');
        $this->postJson("/api/v1/groups/$groupId/members", ['email' => $member->email])->assertCreated();
        $this->postJson("/api/v1/groups/$groupId/start")->assertOk();

        $memberId = GroupMember::where('user_id', $member->id)->value('id');
        $pay = ['group_member_id' => $memberId, 'period_number' => 1, 'amount' => 50, 'payment_method' => 'cash'];

        $contributionId = $this->postJson("/api/v1/groups/$groupId/contributions", $pay)
            ->assertCreated()->json('id');

        // paying the same period again is rejected
        $this->postJson("/api/v1/groups/$groupId/contributions", $pay)->assertStatus(409);

        // reverse it fully, then a further correction is rejected
        $this->postJson("/api/v1/groups/$groupId/contributions/$contributionId/corrections",
            ['amount' => 50, 'reason' => 'wrong person'])->assertCreated();
        $this->getJson("/api/v1/groups/$groupId/contributions")
            ->assertOk()
            ->assertJsonPath('0.corrections.0.contribution_id', $contributionId);
        $this->postJson("/api/v1/groups/$groupId/contributions/$contributionId/corrections",
            ['amount' => 1, 'reason' => 'again'])->assertStatus(422);

        // the period is open again
        $this->postJson("/api/v1/groups/$groupId/contributions", $pay)->assertCreated();
    }

    public function test_payment_below_contribution_amount_is_rejected(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();

        Sanctum::actingAs($admin);
        $groupId = $this->postJson('/api/v1/groups', $this->groupBody())->json('id');
        $this->postJson("/api/v1/groups/$groupId/members", ['email' => $member->email])
            ->assertCreated();
        $this->postJson("/api/v1/groups/$groupId/start")->assertOk();

        $memberId = GroupMember::where('user_id', $member->id)->value('id');

        $this->postJson("/api/v1/groups/$groupId/contributions", [
            'group_member_id' => $memberId,
            'period_number' => 1,
            'amount' => 49,
            'payment_method' => 'cash',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->assertDatabaseCount('contributions', 0);
    }
    public function test_payment_must_equal_contribution_amount(): void
{
        $admin = User::factory()->create();
        $member = User::factory()->create();

        Sanctum::actingAs($admin);
        $groupId = $this->postJson('/api/v1/groups', $this->groupBody())->json('id');
        $this->postJson("/api/v1/groups/$groupId/members", ['email' => $member->email])
            ->assertCreated();
        $this->postJson("/api/v1/groups/$groupId/start")->assertOk();

        $memberId = GroupMember::where('user_id', $member->id)->value('id');
        $group = Group::find($groupId);

    $this->postJson("/api/v1/groups/{$group->id}/contributions", [
        'group_member_id' => $memberId,
        'period_number' => 1,
        'amount' => $group->contribution_amount - 1,
        'payment_method' => 'cash',
    ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
}

    public function test_payout_goes_to_turn_owner_with_net_pot(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();

        Sanctum::actingAs($admin);
        $groupId = $this->postJson('/api/v1/groups', $this->groupBody())->json('id');
        $this->postJson("/api/v1/groups/$groupId/members", ['email' => $member->email])->assertCreated();
        $this->postJson("/api/v1/groups/$groupId/start")->assertOk();

        $memberRows = GroupMember::where('group_id', $groupId)->get();
        foreach ($memberRows as $m) {
            $this->postJson("/api/v1/groups/$groupId/contributions", [
                'group_member_id' => $m->id, 'period_number' => 1,
                'amount' => 50, 'payment_method' => 'cash',
            ])->assertCreated();
        }

        $this->postJson("/api/v1/groups/$groupId/payouts", ['period_number' => 1])
            ->assertCreated()
            ->assertJsonPath('payout.amount', 100)
            ->assertJsonPath('short_by', 0);

        // paying the same period twice is refused
        $this->postJson("/api/v1/groups/$groupId/payouts", ['period_number' => 1])->assertStatus(409);

        // a future period is refused
        $this->postJson("/api/v1/groups/$groupId/payouts", ['period_number' => 2])->assertStatus(422);

        // a plain member cannot record payouts
        Sanctum::actingAs($member);
        $this->postJson("/api/v1/groups/$groupId/payouts", ['period_number' => 1])->assertForbidden();

        $turnOwner = Turn::where('group_id', $groupId)->where('turn_number', 1)->value('group_member_id');
        $this->assertDatabaseHas('payouts', [
            'group_id' => $groupId,
            'period_number' => 1,
            'group_member_id' => $turnOwner,
        ]);
    }

    public function test_duplicate_payout_for_same_period_is_rejected(): void
{
    $admin = User::factory()->create();
    $member = User::factory()->create();

    Sanctum::actingAs($admin);
    $groupId = $this->postJson('/api/v1/groups', $this->groupBody())->json('id');
    $this->postJson("/api/v1/groups/$groupId/members", ['email' => $member->email])->assertCreated();
    $this->postJson("/api/v1/groups/$groupId/start")->assertOk();

    $memberRows = GroupMember::where('group_id', $groupId)->get();
    foreach ($memberRows as $m) {
        $this->postJson("/api/v1/groups/$groupId/contributions", [
            'group_member_id' => $m->id, 'period_number' => 1,
            'amount' => 50, 'payment_method' => 'cash',
        ])->assertCreated();
    }

    // first payout for period 1 - succeeds
    $this->postJson("/api/v1/groups/$groupId/payouts", ['period_number' => 1])
        ->assertCreated()
        ->assertJsonPath('payout.amount', 100)
        ->assertJsonPath('short_by', 0);

    // second payout for same period 1 - rejected
    $this->postJson("/api/v1/groups/$groupId/payouts", ['period_number' => 1])
        ->assertStatus(409)
        ->assertJsonFragment(['message' => 'This period has already been paid out.']);

    // only 1 payout was created
    $this->assertDatabaseCount('payouts', 1);
}

public function test_contributions_list_is_members_only(): void
{
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();

    Sanctum::actingAs($admin);
    $groupId = $this->postJson('/api/v1/groups', $this->groupBody())->json('id');

    $this->postJson("/api/v1/groups/$groupId/members", ['email' => $member->email])
        ->assertCreated();

    $group = Group::find($groupId);

    // plain member can list contributions
    Sanctum::actingAs($member);
    $this->getJson("/api/v1/groups/{$group->id}/contributions")->assertOk();

    // outsider gets 404 - group doesn't exist for them
    Sanctum::actingAs($outsider);
    $this->getJson("/api/v1/groups/{$group->id}/contributions")->assertNotFound();

    // admin can also list (sanity check)
    Sanctum::actingAs($admin);
    $this->getJson("/api/v1/groups/{$group->id}/contributions")->assertOk();
}


    public function test_settlement_splits_qualified_and_refunded(): void
    {
        $admin = User::factory()->create();
        $m1 = User::factory()->create();
        $m2 = User::factory()->create();

        Sanctum::actingAs($admin);
        $gid = $this->postJson('/api/v1/groups', $this->lumpSumBody())->assertCreated()->json('id');
        $this->postJson("/api/v1/groups/$gid/members", ['email' => $m1->email])->assertCreated();
        $this->postJson("/api/v1/groups/$gid/members", ['email' => $m2->email])->assertCreated();
        $this->postJson("/api/v1/groups/$gid/start")->assertOk();

        $ids = GroupMember::where('group_id', $gid)->orderBy('id')->pluck('id');
        $this->pay($gid, $ids[0], 50);
        $this->pay($gid, $ids[1], 50);
        $this->pay($gid, $ids[2], 50);

        Sanctum::actingAs($m1);
        $this->postJson("/api/v1/groups/$gid/settle", ['spent' => 100])->assertForbidden();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/groups/$gid/settle", ['spent' => 100])->assertNotFound();

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/groups/$gid/settle", ['spent' => 100])
            ->assertCreated()
            ->assertJsonPath('settlement.pool', 150)
            ->assertJsonPath('settlement.qualified_count', 3)
            ->assertJsonPath('left_over', 50);

        $this->assertDatabaseHas('settlement_lines', [
            'group_member_id' => $ids[2],
            'outcome' => 'items',
            'amount' => 50,
        ]);
        $this->assertDatabaseHas('groups', ['id' => $gid, 'status' => 'finished']);

        $this->postJson("/api/v1/groups/$gid/settle", ['spent' => 100])->assertStatus(409);
    }

    public function test_settlement_rejects_spending_more_than_pool(): void
    {
        $admin = User::factory()->create();
        $m1 = User::factory()->create();

        Sanctum::actingAs($admin);
        $gid = $this->postJson('/api/v1/groups', $this->lumpSumBody())->json('id');
        $this->postJson("/api/v1/groups/$gid/members", ['email' => $m1->email])->assertCreated();
        $this->postJson("/api/v1/groups/$gid/start")->assertOk();

        foreach (GroupMember::where('group_id', $gid)->pluck('id') as $id) {
            $this->pay($gid, $id, 50);
        }

        $this->postJson("/api/v1/groups/$gid/settle", ['spent' => 500])->assertStatus(422);
    }

    public function test_behind_list_is_admin_only(): void
    {
        $admin = User::factory()->create();
        $m1 = User::factory()->create();

        Sanctum::actingAs($admin);
        $gid = $this->postJson('/api/v1/groups', $this->lumpSumBody())->json('id');
        $this->postJson("/api/v1/groups/$gid/members", ['email' => $m1->email])->assertCreated();
        $this->postJson("/api/v1/groups/$gid/start")->assertOk();

        $ids = GroupMember::where('group_id', $gid)->orderBy('id')->pluck('id');
        $this->pay($gid, $ids[0], 50);

        $this->getJson("/api/v1/groups/$gid/behind")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.short_by', 50);

        Sanctum::actingAs($m1);
        $this->getJson("/api/v1/groups/$gid/behind")->assertForbidden();
    }

    public function test_payout_needs_a_full_pot(): void
    {
        $admin = User::factory()->create();
        $m1 = User::factory()->create();

        Sanctum::actingAs($admin);
        $gid = $this->postJson('/api/v1/groups', $this->groupBody(['contribution_amount' => 50]))->json('id');
        $this->postJson("/api/v1/groups/$gid/members", ['email' => $m1->email])->assertCreated();
        $this->postJson("/api/v1/groups/$gid/start")->assertOk();

        $ids = GroupMember::where('group_id', $gid)->pluck('id');
        $this->pay($gid, $ids[0], 50);

        $this->postJson("/api/v1/groups/$gid/payouts", ['period_number' => 1])->assertStatus(422);

        $this->pay($gid, $ids[1], 50);
        $this->postJson("/api/v1/groups/$gid/payouts", ['period_number' => 1])
            ->assertCreated()
            ->assertJsonPath('payout.amount', 100);

        $this->postJson("/api/v1/groups/$gid/payouts", ['period_number' => 1])->assertStatus(409);
        $this->postJson("/api/v1/groups/$gid/payouts", ['period_number' => 2])->assertStatus(422);
    }

    public function test_logout_revokes_the_token(): void
    {
        $token = $this->postJson('/api/v1/register', [
            'name' => 'Aminata', 'email' => 'x@example.com',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->json('token');

        $this->withToken($token)->getJson('/api/v1/groups')->assertOk();

        $this->withToken($token)->postJson('/api/v1/logout')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/groups')->assertUnauthorized();
    }

    public function test_token_expires_after_seven_days(): void
    {
        $token = $this->postJson('/api/v1/register', [
            'name' => 'Aminata', 'email' => 'y@example.com',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->json('token');

        $this->travel(8)->days();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/groups')->assertUnauthorized();
    }

    public function test_payout_history_is_visible_to_members_only(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();

        Sanctum::actingAs($admin);
        $gid = $this->postJson('/api/v1/groups', $this->groupBody())->json('id');
        $this->postJson("/api/v1/groups/$gid/members", ['email' => $member->email])->assertCreated();

        $memberRow = GroupMember::where('group_id', $gid)->where('user_id', $member->id)->first();
        $p = new Payout;
        $p->group_id = $gid;
        $p->period_number = 1;
        $p->group_member_id = $memberRow->id;
        $p->amount = 100;
        $p->recorded_by = $admin->id;
        $p->paid_at = now();
        $p->save();

        Sanctum::actingAs($member);
        $this->getJson("/api/v1/groups/$gid/payouts")->assertOk()->assertJsonCount(1);

        Sanctum::actingAs($outsider);
        $this->getJson("/api/v1/groups/$gid/payouts")->assertNotFound();
    }

    public function test_contributions_are_visible_to_members_but_not_outsiders(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();

        Sanctum::actingAs($admin);
        $gid = $this->postJson('/api/v1/groups', $this->groupBody())->json('id');
        $this->postJson("/api/v1/groups/$gid/members", ['email' => $member->email])
            ->assertCreated();
        $this->postJson("/api/v1/groups/$gid/start")->assertOk();

        $memberId = GroupMember::where('group_id', $gid)
            ->where('user_id', $member->id)
            ->value('id');

        $this->pay($gid, $memberId, 50);

        Sanctum::actingAs($member);
        $this->getJson("/api/v1/groups/$gid/contributions")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.member.user.name', $member->name);

        Sanctum::actingAs($outsider);
        $this->getJson("/api/v1/groups/$gid/contributions")
            ->assertNotFound();
    }
}
