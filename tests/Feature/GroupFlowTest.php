<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GroupFlowTest extends TestCase
{
    use RefreshDatabase;

    private function groupBody(array $override = []): array
    {
        return array_merge([
            'name' => 'Test Osusu',
            'contribution_amount' => 5000,
            'frequency' => 'weekly',
            'total_payments' => 4,
            'start_date' => now()->toDateString(),
            'payout_type' => 'rotating',
        ], $override);
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
        $groupId = $this->postJson('/api/v1/groups', $this->groupBody())->json('id');

        $this->postJson("/api/v1/groups/$groupId/members", ['email' => $users[0]->email])->assertCreated();
        $this->postJson("/api/v1/groups/$groupId/members", ['email' => $users[1]->email])->assertCreated();
        $this->postJson("/api/v1/groups/$groupId/start")->assertOk();

        $turns = GroupMember::where('group_id', $groupId)->pluck('turn_order')->sort()->values()->all();
        $this->assertEquals([1, 2, 3], $turns);

        $this->postJson("/api/v1/groups/$groupId/members", ['email' => $users[2]->email])->assertCreated();
        $this->postJson("/api/v1/groups/$groupId/members", ['email' => $users[3]->email])->assertStatus(422);
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
}