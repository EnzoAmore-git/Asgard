<?php

namespace App\Services\Clubs;

use App\Enums\Permission;
use App\Models\Club;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClubService
{
    public function __construct(private ClubAccess $access) {}

    public function nameKey(string $name): string
    {
        $normalized = \Normalizer::normalize(trim(preg_replace('/\s+/u', ' ', $name)), \Normalizer::FORM_C);

        return mb_strtolower($normalized);
    }

    public function create(User $operator, User $owner, array $data): Club
    {
        abort_unless($operator->is_operator, 403);
        foreach (['name', 'owner_name'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = trim(preg_replace('/\s+/u', ' ', $data[$field]));
            }
        }
        Validator::make($data, [
            'name' => 'required|string|min:2|max:100', 'owner_name' => 'required|string|min:2|max:80',
            'timezone' => 'required|timezone', 'currency' => 'required|regex:/^[A-Z]{3}$/',
            'period_start_day' => 'required|integer|between:1,31',
        ])->validate();

        return DB::transaction(function () use ($operator, $owner, $data) {
            $club = Club::create(collect($data)->only(['name', 'timezone', 'currency', 'period_start_day'])->all());
            $member = Membership::create([
                'club_id' => $club->id, 'user_id' => $owner->id, 'club_name' => trim($data['owner_name']),
                'name_key' => $this->nameKey($data['owner_name']), 'status' => 'active',
            ]);
            $club->owner_membership_id = $member->id;
            $club->save();
            Audit::record($operator->id, 'club.created', ['owner_membership_id' => $member->id], $club->id);

            return $club;
        }, 3);
    }

    public function invite(User $actor, Club $club): array
    {
        return DB::transaction(function () use ($actor, $club) {
            $club = Club::lockForUpdate()->findOrFail($club->id);
            $this->access->authorize($actor, $club, 'invitations.manage');
            $token = Str::random(64);
            $invitation = Invitation::create([
                'club_id' => $club->id, 'created_by' => $actor->id,
                'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7),
            ]);
            Audit::record($actor->id, 'invitation.created', ['invitation_id' => $invitation->id], $club->id);

            return ['id' => $invitation->id, 'url' => url('/invite/'.$token), 'expires_at' => $invitation->expires_at->toIso8601String()];
        }, 3);
    }

    public function revoke(User $actor, Club $club, int $id): void
    {
        DB::transaction(function () use ($actor, $club, $id) {
            $club = Club::lockForUpdate()->findOrFail($club->id);
            $this->access->authorize($actor, $club, 'invitations.manage');
            $invitation = Invitation::where('club_id', $club->id)->findOrFail($id);
            if ($invitation->revoked_at) {
                return;
            }
            $invitation->revoked_at = now();
            $invitation->save();
            Audit::record($actor->id, 'invitation.revoked', ['invitation_id' => $id], $club->id);
        }, 3);
    }

    public function join(User $user, string $token, string $name): Membership
    {
        Validator::make(['club_name' => $name], ['club_name' => 'required|string|min:2|max:80'])->validate();
        $hash = hash('sha256', $token);
        $candidate = Invitation::where('token_hash', $hash)->firstOrFail();

        return DB::transaction(function () use ($user, $hash, $candidate, $name) {
            // All membership, invitation and ownership changes share the club lock.
            $club = Club::lockForUpdate()->findOrFail($candidate->club_id);
            $invite = Invitation::where('token_hash', $hash)->where('club_id', $club->id)->lockForUpdate()->firstOrFail();
            abort_if($invite->revoked_at || $invite->expires_at->lte(now()), 410, 'Приглашение больше не действует. Попросите новую ссылку.');
            $existing = Membership::where('club_id', $club->id)->where('user_id', $user->id)->first();
            if ($existing) {
                abort_if($existing->status === 'excluded', 403, 'Для возвращения в клуб обратитесь к управляющему.');

                return $existing;
            }
            $key = $this->nameKey($name);
            if ($key === '' || Membership::where('club_id', $club->id)->where('name_key', $key)->exists()) {
                throw ValidationException::withMessages(['club_name' => 'Это клубное имя уже занято.']);
            }
            $member = Membership::create([
                'club_id' => $club->id, 'user_id' => $user->id, 'club_name' => trim($name),
                'name_key' => $key, 'status' => 'active',
            ]);
            Audit::record($user->id, 'membership.joined', ['membership_id' => $member->id, 'invitation_id' => $invite->id], $club->id);

            return $member;
        }, 3);
    }

    public function setPermissions(User $actor, Club $club, int $target, array $permissions): void
    {
        Validator::make(['permissions' => $permissions], [
            'permissions' => 'array|max:4', 'permissions.*' => ['required', 'distinct', Rule::enum(Permission::class)],
        ])->validate();
        DB::transaction(function () use ($actor, $club, $target, $permissions) {
            $club = Club::lockForUpdate()->findOrFail($club->id);
            $this->access->authorize($actor, $club, 'permissions.manage');
            $member = Membership::where('club_id', $club->id)->findOrFail($target);
            abort_if($member->status === 'excluded', 409, 'Сначала верните участника в клуб.');
            $old = DB::table('permission_grants')->where('club_id', $club->id)->where('membership_id', $member->id)->pluck('permission')->all();
            DB::table('permission_grants')->where('club_id', $club->id)->where('membership_id', $member->id)->delete();
            foreach ($permissions as $permission) {
                DB::table('permission_grants')->insert([
                    'club_id' => $club->id, 'membership_id' => $member->id, 'permission' => $permission,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            Audit::record($actor->id, 'permissions.changed', ['membership_id' => $target, 'before' => $old, 'after' => $permissions], $club->id);
        }, 3);
    }

    public function transfer(User $actor, Club $club, int $target): void
    {
        DB::transaction(function () use ($actor, $club, $target) {
            $club = Club::lockForUpdate()->findOrFail($club->id);
            $this->access->authorize($actor, $club, 'ownership.transfer');
            $newOwner = Membership::where('club_id', $club->id)->findOrFail($target);
            abort_unless($newOwner->effectiveStatus() === 'active', 409, 'Новый владелец должен быть действующим участником.');
            abort_if($newOwner->id === (int) $club->owner_membership_id, 409, 'Участник уже владеет клубом.');
            $before = $club->owner_membership_id;
            $club->owner_membership_id = $newOwner->id;
            $club->save();
            Audit::record($actor->id, 'ownership.transferred', ['before' => $before, 'after' => $target], $club->id);
        }, 3);
    }

    public function status(User $actor, Club $club, int $target, array $data): void
    {
        Validator::make($data, [
            'status' => ['required', Rule::in(['active', 'frozen', 'excluded'])],
            'reason' => 'required|string|min:2|max:500', 'frozen_until' => 'nullable|date|after:now',
        ])->validate();
        DB::transaction(function () use ($actor, $club, $target, $data) {
            $club = Club::lockForUpdate()->findOrFail($club->id);
            $this->access->authorize($actor, $club, 'members.manage');
            $member = Membership::where('club_id', $club->id)->findOrFail($target);
            abort_if((int) $club->owner_membership_id === $member->id && $data['status'] !== 'active', 409, 'Сначала передайте владение другому участнику.');
            $before = $member->status;
            $member->status = $data['status'];
            $member->frozen_until = $data['status'] === 'frozen' ? ($data['frozen_until'] ?? null) : null;
            $member->save();
            DB::table('membership_status_history')->insert([
                'club_id' => $club->id, 'membership_id' => $member->id, 'actor_id' => $actor->id,
                'from_status' => $before, 'to_status' => $member->status, 'frozen_until' => $member->frozen_until,
                'reason' => $data['reason'], 'created_at' => now(),
            ]);
            Audit::record($actor->id, 'membership.status_changed', ['membership_id' => $target, 'before' => $before, 'after' => $member->status], $club->id);
        }, 3);
    }
}
