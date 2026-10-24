<?php

namespace Tests\Feature\Clubs;

use App\Models\Club;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use App\Services\Clubs\ClubAccess;
use App\Services\Clubs\ClubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClubAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $operator;

    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->operator = User::factory()->create(['is_operator' => true]);
        $this->club = app(ClubService::class)->create($this->operator, $this->owner, [
            'name' => 'Асгард', 'owner_name' => 'Рагнар', 'timezone' => 'Europe/Moscow', 'currency' => 'RUB', 'period_start_day' => 10,
        ]);
    }

    private function invitation(): array
    {
        $invite = app(ClubService::class)->invite($this->owner, $this->club);
        $invite['token'] = basename($invite['url']);

        return $invite;
    }

    private function join(User $user, string $name = 'Бьорн'): Membership
    {
        return app(ClubService::class)->join($user, $this->invitation()['token'], $name);
    }

    public function test_guest_cannot_read_clubs_or_create_membership(): void
    {
        $this->getJson('/api/clubs')->assertUnauthorized();
        $this->postJson('/api/invitations/'.$this->invitation()['token'].'/accept', ['club_name' => 'Бьорн'])->assertUnauthorized();
    }

    public function test_operator_has_no_automatic_club_access_and_no_public_club_creation(): void
    {
        $this->actingAs($this->operator)->getJson('/api/clubs/'.$this->club->id)->assertNotFound();
        $this->postJson('/api/clubs', ['name' => 'Чужой клуб'])->assertStatus(405);
    }

    public function test_shared_invitation_accepts_multiple_people_and_expires_after_seven_days(): void
    {
        $invite = $this->invitation();
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, Invitation::find($invite['id'])->expires_at->timestamp, 2);
        $this->assertDatabaseMissing('invitations', ['token_hash' => $invite['token']]);
        foreach (['Бьорн', 'Тор'] as $name) {
            $this->actingAs(User::factory()->create())->postJson('/api/invitations/'.$invite['token'].'/accept', ['club_name' => $name])->assertOk();
        }
        $this->assertDatabaseCount('memberships', 3);
        $this->travel(8)->days();
        $this->actingAs(User::factory()->create())->postJson('/api/invitations/'.$invite['token'].'/accept', ['club_name' => 'Эрик'])->assertGone();
    }

    public function test_repeated_join_preserves_membership_and_name(): void
    {
        $user = User::factory()->create();
        $invite = $this->invitation();
        $this->actingAs($user)->postJson('/api/invitations/'.$invite['token'].'/accept', ['club_name' => 'Бьорн'])->assertOk();
        $this->postJson('/api/invitations/'.$invite['token'].'/accept', ['club_name' => 'Новое имя'])->assertOk();
        $this->assertDatabaseCount('memberships', 2);
        $this->assertDatabaseHas('memberships', ['user_id' => $user->id, 'club_name' => 'Бьорн']);
    }

    public function test_club_name_is_case_and_whitespace_normalized_within_club(): void
    {
        $this->join(User::factory()->create(), 'Бьорн');
        $invite = $this->invitation();
        $this->actingAs(User::factory()->create())->postJson('/api/invitations/'.$invite['token'].'/accept', ['club_name' => '  БЬОРН  '])->assertUnprocessable();
    }

    public function test_revoked_link_cannot_be_used_and_hash_is_not_listed(): void
    {
        $invite = $this->invitation();
        $this->actingAs($this->owner)->getJson('/api/clubs/'.$this->club->id.'/invitations')->assertOk()->assertJsonMissingPath('0.token_hash');
        $this->deleteJson('/api/clubs/'.$this->club->id.'/invitations/'.$invite['id'])->assertNoContent();
        $this->actingAs(User::factory()->create())->postJson('/api/invitations/'.$invite['token'].'/accept', ['club_name' => 'Тор'])->assertGone();
    }

    public function test_club_a_member_cannot_read_or_modify_club_b_even_with_known_ids(): void
    {
        $other = app(ClubService::class)->create($this->operator, User::factory()->create(), [
            'name' => 'Другой клуб', 'owner_name' => 'Рагнар', 'timezone' => 'Europe/Moscow', 'currency' => 'RUB', 'period_start_day' => 10,
        ]);
        $this->actingAs($this->owner)->getJson('/api/clubs')->assertJsonCount(1);
        foreach (['', '/members', '/invitations', '/audit'] as $suffix) {
            $this->getJson('/api/clubs/'.$other->id.$suffix)->assertNotFound();
        }
        $this->postJson('/api/clubs/'.$other->id.'/invitations')->assertNotFound();
        $this->putJson('/api/clubs/'.$this->club->id.'/members/'.$other->owner_membership_id.'/permissions', ['permissions' => ['finance.manage']])->assertNotFound();
        $this->postJson('/api/clubs/'.$this->club->id.'/ownership', ['membership_id' => $other->owner_membership_id])->assertNotFound();
    }

    public function test_owner_and_manager_do_not_inherit_financial_powers(): void
    {
        $member = $this->join($user = User::factory()->create());
        app(ClubService::class)->setPermissions($this->owner, $this->club, $member->id, ['members.manage', 'schedule.manage']);
        $access = app(ClubAccess::class);
        $this->assertNotContains('finance.view', $access->capabilities(Membership::find($this->club->owner_membership_id), $this->club));
        $this->assertNotContains('finance.manage', $access->capabilities($member, $this->club));
        $this->actingAs($user)->putJson('/api/clubs/'.$this->club->id.'/members/'.$member->id.'/permissions', ['permissions' => ['finance.manage']])->assertForbidden();
        $this->postJson('/api/clubs/'.$this->club->id.'/invitations')->assertCreated();
    }

    public function test_permissions_are_combined_and_cash_receiver_does_not_get_financial_history(): void
    {
        $member = $this->join($user = User::factory()->create());
        app(ClubService::class)->setPermissions($this->owner, $this->club, $member->id, ['cash.receive', 'schedule.manage']);
        $capabilities = app(ClubAccess::class)->capabilities($member, $this->club);
        $this->assertContains('cash.receive', $capabilities);
        $this->assertContains('schedule.manage', $capabilities);
        $this->assertNotContains('finance.view', $capabilities);
        $this->actingAs($this->owner)->putJson('/api/clubs/'.$this->club->id.'/members/'.$member->id.'/permissions', ['permissions' => []])->assertNoContent();
        $this->assertNotContains('cash.receive', app(ClubAccess::class)->capabilities($member, $this->club));
    }

    public function test_permission_payload_cannot_grant_ownership_or_platform_operator(): void
    {
        $member = $this->join(User::factory()->create());
        $this->actingAs($this->owner)->putJson('/api/clubs/'.$this->club->id.'/members/'.$member->id.'/permissions', ['permissions' => ['permissions.manage', 'is_operator']])->assertUnprocessable();
        $this->assertDatabaseCount('permission_grants', 0);
    }

    public function test_exclusion_cannot_be_bypassed_and_reacceptance_preserves_id(): void
    {
        $user = User::factory()->create();
        $member = $this->join($user);
        app(ClubService::class)->setPermissions($this->owner, $this->club, $this->club->owner_membership_id, ['members.manage']);
        $path = '/api/clubs/'.$this->club->id.'/members/'.$member->id.'/status';
        $this->actingAs($this->owner)->putJson($path, ['status' => 'excluded', 'reason' => 'Проверка исключения'])->assertNoContent();
        $invite = $this->invitation();
        $this->actingAs($user)->postJson('/api/invitations/'.$invite['token'].'/accept', ['club_name' => 'Другое имя'])->assertForbidden();
        $this->actingAs($this->owner)->putJson($path, ['status' => 'active', 'reason' => 'Участник вернулся'])->assertNoContent();
        $this->assertDatabaseHas('memberships', ['id' => $member->id, 'status' => 'active']);
        $this->assertDatabaseCount('membership_status_history', 2);
    }

    public function test_freeze_has_end_date_and_does_not_destroy_history(): void
    {
        $member = $this->join(User::factory()->create());
        app(ClubService::class)->setPermissions($this->owner, $this->club, $this->club->owner_membership_id, ['members.manage']);
        app(ClubService::class)->status($this->owner, $this->club, $member->id, ['status' => 'frozen', 'reason' => 'Отъезд', 'frozen_until' => now()->addDays(2)->toIso8601String()]);
        $this->assertSame('frozen', $member->fresh()->effectiveStatus());
        $this->travel(3)->days();
        $this->assertSame('active', $member->fresh()->effectiveStatus());
        $this->assertDatabaseCount('membership_status_history', 1);
    }

    public function test_transfer_preserves_one_owner_and_old_owner_cannot_transfer_again(): void
    {
        $member = $this->join($user = User::factory()->create());
        $this->actingAs($this->owner)->postJson('/api/clubs/'.$this->club->id.'/ownership', ['membership_id' => $member->id])->assertNoContent();
        $this->postJson('/api/clubs/'.$this->club->id.'/ownership', ['membership_id' => $this->club->owner_membership_id])->assertForbidden();
        $this->assertSame($member->id, (int) $this->club->fresh()->owner_membership_id);
        $this->assertContains('permissions.manage', app(ClubAccess::class)->capabilities($member, $this->club->fresh()));
        $this->assertNotContains('finance.view', app(ClubAccess::class)->capabilities($member, $this->club->fresh()));
    }

    public function test_last_owner_cannot_be_excluded_or_frozen(): void
    {
        app(ClubService::class)->setPermissions($this->owner, $this->club, $this->club->owner_membership_id, ['members.manage']);
        foreach (['frozen', 'excluded'] as $status) {
            $this->actingAs($this->owner)->putJson('/api/clubs/'.$this->club->id.'/members/'.$this->club->owner_membership_id.'/status', ['status' => $status, 'reason' => 'Проверка защиты'])->assertConflict();
        }
        $this->assertDatabaseCount('membership_status_history', 0);
    }
}
