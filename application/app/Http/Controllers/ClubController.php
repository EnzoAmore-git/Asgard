<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Invitation;
use App\Models\Membership;
use App\Services\Clubs\ClubAccess;
use App\Services\Clubs\ClubService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClubController extends Controller
{
    public function index(Request $request, ClubAccess $access)
    {
        $members = Membership::where('user_id', $request->user()->id)->get();

        return response()->json(Club::whereIn('id', $members->pluck('club_id'))->get()->map(function (Club $club) use ($members, $access) {
            $member = $members->firstWhere('club_id', $club->id);

            return ['id' => $club->id, 'name' => $club->name, 'membership_id' => $member->id,
                'club_name' => $member->club_name, 'status' => $member->effectiveStatus(),
                'owner_membership_id' => $club->owner_membership_id, 'capabilities' => $access->capabilities($member, $club)];
        }));
    }

    public function show(Request $request, Club $club, ClubAccess $access)
    {
        $member = $access->member($request->user(), $club);

        return response()->json(['id' => $club->id, 'name' => $club->name, 'timezone' => $club->timezone,
            'membership_id' => $member->id, 'club_name' => $member->club_name,
            'status' => $member->effectiveStatus(), 'capabilities' => $access->capabilities($member, $club)]);
    }

    public function members(Request $request, Club $club, ClubAccess $access)
    {
        $access->authorize($request->user(), $club, 'members.view');
        $page = Membership::where('club_id', $club->id)->orderBy('id')->paginate(50);
        $permissions = DB::table('permission_grants')->where('club_id', $club->id)->get()->groupBy('membership_id');

        return response()->json(['data' => $page->getCollection()->map(fn (Membership $m) => [
            'id' => $m->id, 'club_name' => $m->club_name, 'status' => $m->effectiveStatus(),
            'frozen_until' => $m->frozen_until?->toIso8601String(), 'owner' => $m->id === (int) $club->owner_membership_id,
            'permissions' => ($permissions[$m->id] ?? collect())->pluck('permission')->values(),
        ]), 'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null]);
    }

    public function invitations(Request $request, Club $club, ClubAccess $access)
    {
        $access->authorize($request->user(), $club, 'invitations.manage');

        return response()->json(Invitation::where('club_id', $club->id)->whereNull('revoked_at')
            ->where('expires_at', '>', now())->orderByDesc('id')->get(['id', 'expires_at', 'created_at']));
    }

    public function invite(Request $request, Club $club, ClubService $clubs)
    {
        return response()->json($clubs->invite($request->user(), $club), 201);
    }

    public function revoke(Request $request, Club $club, int $invitation, ClubService $clubs)
    {
        $clubs->revoke($request->user(), $club, $invitation);

        return response()->noContent();
    }

    public function accept(Request $request, string $token, ClubService $clubs)
    {
        $data = $request->validate(['club_name' => 'required|string|min:2|max:80']);
        $member = $clubs->join($request->user(), $token, $data['club_name']);
        $request->session()->forget('pending_invitation');

        return response()->json(['club_id' => $member->club_id, 'membership_id' => $member->id]);
    }

    public function invitationInfo(string $token)
    {
        $invitation = Invitation::where('token_hash', hash('sha256', $token))->firstOrFail();
        abort_if($invitation->revoked_at || $invitation->expires_at->lte(now()), 410, 'Приглашение больше не действует. Попросите новую ссылку.');

        return response()->json(['club_name' => Club::findOrFail($invitation->club_id)->name, 'expires_at' => $invitation->expires_at->toIso8601String()]);
    }

    public function permissions(Request $request, Club $club, int $membership, ClubService $clubs)
    {
        $data = $request->validate(['permissions' => 'present|array']);
        $clubs->setPermissions($request->user(), $club, $membership, $data['permissions']);

        return response()->noContent();
    }

    public function transfer(Request $request, Club $club, ClubService $clubs)
    {
        $data = $request->validate(['membership_id' => 'required|integer']);
        $clubs->transfer($request->user(), $club, $data['membership_id']);

        return response()->noContent();
    }

    public function status(Request $request, Club $club, int $membership, ClubService $clubs)
    {
        $clubs->status($request->user(), $club, $membership, $request->only(['status', 'reason', 'frozen_until']));

        return response()->noContent();
    }

    public function audit(Request $request, Club $club, ClubAccess $access)
    {
        $access->authorize($request->user(), $club, 'permissions.manage');

        return response()->json(DB::table('audit_events')->where('club_id', $club->id)
            ->whereIn('event', ['club.created', 'membership.joined', 'permissions.changed', 'ownership.transferred',
                'invitation.created', 'invitation.revoked', 'membership.status_changed'])->orderByDesc('id')->paginate(30));
    }
}
