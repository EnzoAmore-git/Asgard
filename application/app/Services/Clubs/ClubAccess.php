<?php

namespace App\Services\Clubs;

use App\Models\Club;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ClubAccess
{
    public function member(User $user, Club $club): Membership
    {
        return Membership::where('club_id', $club->id)->where('user_id', $user->id)
            ->where('status', '!=', 'excluded')->firstOrFail();
    }

    public function capabilities(Membership $member, Club $club): array
    {
        if ($member->club_id !== $club->id || $member->status === 'excluded') {
            return [];
        }
        $permissions = DB::table('permission_grants')->where('club_id', $club->id)
            ->where('membership_id', $member->id)->pluck('permission')->all();
        if ((int) $club->owner_membership_id === $member->id) {
            $permissions = [...$permissions, 'permissions.manage', 'ownership.transfer', 'invitations.manage', 'members.view'];
        }
        if (in_array('members.manage', $permissions, true)) {
            $permissions = [...$permissions, 'invitations.manage', 'members.view'];
        }
        if (in_array('finance.manage', $permissions, true)) {
            $permissions[] = 'finance.view';
        }

        return array_values(array_unique($permissions));
    }

    public function authorize(User $user, Club $club, string $permission): Membership
    {
        $member = $this->member($user, $club);
        abort_unless(in_array($permission, $this->capabilities($member, $club), true), 403, 'Недостаточно прав для этого действия.');

        return $member;
    }
}
