<?php

namespace Tests\Feature;

use App\Models\GroupMember;
use App\Models\Turn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use App\Models\Payout;
use App\Models\Settlement;

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

    public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Aminata',
            'email' => 'a@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertCreated()->assertJsonStructure(['user', 'token']);
        $response->assertJsonMissingPath('user.password');
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
        $pay = ['group_member_id' => $memberId, 'period_number' => 1, 'amount' => 5000, 'payment_method' => 'cash'];

        $contributionId = $this->postJson("/api/v1/groups/$groupId/contributions", $pay)
            ->assertCreated()->json('id');

        // paying the same period again is refused
        $this->postJson("/api/v1/groups/$groupId/contributions", $pay)->assertStatus(409);

        // reverse it fully, then a further correction is refused
        $this->postJson("/api/v1/groups/$groupId/contributions/$contributionId/corrections",
            ['amount' => 5000, 'reason' => 'wrong person'])->assertCreated();
        $this->postJson("/api/v1/groups/$groupId/contributions/$contributionId/corrections",
            ['amount' => 1, 'reason' => 'again'])->assertStatus(422);

        // the period is open again
        $this->postJson("/api/v1/groups/$groupId/contributions", $pay)->assertCreated();
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
        $this->pay($gid, $ids[2], 20);

        Sanctum::actingAs($m1);
        $this->postJson("/api/v1/groups/$gid/settle", ['spent' => 100])->assertForbidden();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/groups/$gid/settle", ['spent' => 100])->assertNotFound();

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/groups/$gid/settle", ['spent' => 100])
            ->assertCreated()
            ->assertJsonPath('settlement.pool', 100)
            ->assertJsonPath('settlement.qualified_count', 2)
            ->assertJsonPath('left_over', 0);

        $this->assertDatabaseHas('settlement_lines', [
            'group_member_id' => $ids[2],
            'outcome' => 'refund',
            'amount' => 20,
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
        $this->pay($gid, $ids[1], 20);

        $this->getJson("/api/v1/groups/$gid/behind")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.short_by', 30);

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
}
