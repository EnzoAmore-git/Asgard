<?php

namespace App\Services\Training;

use App\Models\Club;
use App\Models\Membership;
use App\Models\User;
use App\Services\Clubs\ClubAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class TrainingRead
{
    public function __construct(private ClubAccess $access, private TrainingAccess $trainingAccess, private Trainings $trainings) {}

    public function index(Club $club, User $user, array $input): array
    {
        $member = $this->access->member($user, $club);
        $data = Validator::make($input, ['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d'])->validate();
        $from = CarbonImmutable::parse($data['from'] ?? CarbonImmutable::now($club->timezone)->startOfMonth()->toDateString(), $club->timezone)->startOfDay();
        $to = CarbonImmutable::parse($data['to'] ?? $from->addMonth()->toDateString(), $club->timezone)->endOfDay();
        abort_if($to->lt($from) || $from->diffInDays($to) > 367, 422, 'Выберите интервал не больше года.');
        $page = DB::table('trainings')->where('club_id', $club->id)->whereBetween('starts_at', [$from->utc(), $to->utc()])->orderBy('starts_at')->orderBy('id')->paginate(50);

        return ['timezone' => $club->timezone, 'data' => $page->getCollection()->map(fn ($t) => $this->row($club, $user, $member, $t)), 'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null];
    }

    public function show(Club $club, User $user, int $id): array
    {
        return DB::transaction(function () use ($club, $user, $id) {
            $member = $this->access->member($user, $club);
            $t = $this->trainings->find($club, $id);
            $row = $this->row($club, $user, $member, $t);
            $row['going'] = DB::table('training_rsvps as r')->join('memberships as m', 'm.id', '=', 'r.membership_id')->where('r.club_id', $club->id)->where('r.training_id', $id)->where('r.response', 'yes')->orderBy('m.id')->get(['m.club_name']);
            $row['roster'] = null;
            if ($this->trainingAccess->canView($club, $user, $t)) {
                $query = Membership::where('club_id', $club->id)->where(function ($q) use ($id) {
                    $q->where('status', '!=', 'excluded')->orWhereIn('id', DB::table('training_rsvps')->where('training_id', $id)->select('membership_id'))->orWhereIn('id', DB::table('training_attendances')->where('training_id', $id)->select('membership_id'));
                });
                $rsvps = DB::table('training_rsvps')->where('training_id', $id)->pluck('response', 'membership_id');
                $att = DB::table('training_attendances')->where('training_id', $id)->get()->keyBy('membership_id');
                $row['roster'] = $query->orderBy('id')->get()->map(fn ($m) => ['id' => $m->id, 'club_name' => $m->club_name, 'status' => $m->effectiveStatus(), 'response' => $rsvps[$m->id] ?? null, 'draft_present' => (bool) ($att[$m->id]->draft_present ?? false), 'confirmed_present' => isset($att[$m->id]) && $att[$m->id]->confirmed_at !== null ? (bool) $att[$m->id]->confirmed_present : null]);
            }

            return $row;
        });
    }

    private function row(Club $club, User $user, Membership $member, object $t): array
    {
        $responsible = Membership::where('club_id', $club->id)->findOrFail($t->responsible_membership_id);
        $response = DB::table('training_rsvps')->where('training_id', $t->id)->where('membership_id', $member->id)->value('response');
        $own = DB::table('training_attendances')->where('training_id', $t->id)->where('membership_id', $member->id)->first();

        return ['id' => $t->id, 'title' => $t->title, 'starts_at' => CarbonImmutable::parse($t->starts_at, 'UTC')->toISOString(), 'local_start' => CarbonImmutable::parse($t->starts_at, 'UTC')->setTimezone($club->timezone)->format('Y-m-d\TH:i'), 'responsible_membership_id' => $responsible->id, 'responsible_name' => $responsible->club_name,
            'status' => $t->status, 'version' => $t->version, 'can_edit_attendance' => $this->trainingAccess->canEdit($club, $user, $t), 'own_response' => $response,
            'own_present' => $own && $own->confirmed_at !== null ? (bool) $own->confirmed_present : null,
            'confirmed_count' => $t->status === 'closed' ? DB::table('training_attendances')->where('training_id', $t->id)->where('confirmed_present', true)->count() : 0];
    }

    public function options(Club $club, User $user): array
    {
        $this->access->authorize($user, $club, 'schedule.manage');
        $page = Membership::where('club_id', $club->id)->where('status', '!=', 'excluded')->orderBy('id')->paginate(50);

        return ['timezone' => $club->timezone, 'members' => $page->getCollection()->map(fn ($m) => ['id' => $m->id, 'club_name' => $m->club_name]), 'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
            'templates' => DB::table('training_templates')->where('club_id', $club->id)->orderByDesc('id')->get()->map(function ($t) {
                $t->weekdays = json_decode($t->weekdays, true, 512, JSON_THROW_ON_ERROR);
                $t->active = (bool) $t->active;

                return $t;
            })];
    }
}
