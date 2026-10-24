<?php

namespace App\Services\Training;

use App\Models\Club;
use App\Models\Membership;
use App\Models\User;
use App\Services\Audit;
use App\Services\Clubs\ClubAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class Trainings
{
    public function __construct(private ClubAction $actions, private Calendar $calendar, private ClubAccess $access, private TrainingAccess $trainingAccess, private Billing $billing) {}

    public function find(Club $club, int $id): object
    {
        return DB::table('trainings')->where('club_id', $club->id)->find($id) ?? abort(404);
    }

    private function result(object $t): array
    {
        return ['id' => $t->id, 'version' => $t->version, 'status' => $t->status];
    }

    private function expected(object $t, int $version): void
    {
        abort_unless($t->version === $version, 409, 'Тренировку уже изменили. Обновите её перед сохранением.');
    }

    private function planned(object $t): void
    {
        abort_unless($t->status === 'planned', 409, 'Закрытую или отмененную тренировку менять нельзя. Исправление явки выполняется отдельно.');
    }

    private function responsible(Club $club, int $id): void
    {
        Membership::where('club_id', $club->id)->where('status', '!=', 'excluded')->findOrFail($id);
    }

    private function workoutData(array $input): array
    {
        return Validator::make($input, ['title' => 'required|string|min:2|max:120', 'local_start' => 'required|date_format:Y-m-d\TH:i', 'responsible_membership_id' => 'required|integer|min:1'])->validate();
    }

    public function create(Club $club, User $actor, string $key, array $input): array
    {
        $data = $this->workoutData($input);

        return $this->actions->run($club, $actor, $key, 'training.create', $data, fn (Club $c) => $this->access->authorize($actor, $c, 'schedule.manage'), function (Club $c) use ($actor, $data) {
            $this->responsible($c, (int) $data['responsible_membership_id']);
            $id = DB::table('trainings')->insertGetId(['club_id' => $c->id, 'title' => $data['title'], 'starts_at' => $this->calendar->localTime($c, $data['local_start']), 'responsible_membership_id' => $data['responsible_membership_id'], 'created_at' => now(), 'updated_at' => now()]);
            $t = $this->find($c, $id);
            $this->event($c, $actor, $t, 'created');

            return $this->result($t);
        });
    }

    public function update(Club $club, User $actor, string $key, int $id, array $input): array
    {
        $data = $this->workoutData($input) + Validator::make($input, ['version' => 'required|integer|min:1'])->validate();
        $data['training_id'] = $id;

        return $this->actions->run($club, $actor, $key, 'training.update', $data, fn (Club $c) => $this->access->authorize($actor, $c, 'schedule.manage'), function (Club $c) use ($actor, $id, $data) {
            $t = $this->find($c, $id);
            $this->planned($t);
            $this->expected($t, (int) $data['version']);
            $this->responsible($c, (int) $data['responsible_membership_id']);
            DB::table('trainings')->where('id', $id)->update(['title' => $data['title'], 'starts_at' => $this->calendar->localTime($c, $data['local_start']), 'responsible_membership_id' => $data['responsible_membership_id'], 'version' => $t->version + 1, 'updated_at' => now()]);
            $t = $this->find($c, $id);
            $this->event($c, $actor, $t, 'updated');

            return $this->result($t);
        });
    }

    public function cancel(Club $club, User $actor, string $key, int $id, array $input): array
    {
        $data = Validator::make($input, ['version' => 'required|integer|min:1', 'reason' => 'required|string|min:2|max:500'])->validate();
        $data['training_id'] = $id;

        return $this->actions->run($club, $actor, $key, 'training.cancel', $data, fn (Club $c) => $this->access->authorize($actor, $c, 'schedule.manage'), function (Club $c) use ($actor, $id, $data) {
            $t = $this->find($c, $id);
            $this->planned($t);
            $this->expected($t, (int) $data['version']);
            abort_unless(CarbonImmutable::parse($t->starts_at, 'UTC')->isFuture(), 409, 'Отменять тренировку можно только заранее, до начала.');
            DB::table('trainings')->where('id', $id)->update(['status' => 'cancelled', 'version' => $t->version + 1, 'updated_at' => now()]);
            $t = $this->find($c, $id);
            $this->event($c, $actor, $t, 'cancelled', $data['reason']);

            return $this->result($t);
        });
    }

    public function rsvp(Club $club, User $actor, string $key, int $id, array $input): array
    {
        $data = Validator::make($input, ['response' => 'required|in:yes,no'])->validate();
        $data['training_id'] = $id;

        return $this->actions->run($club, $actor, $key, 'training.rsvp', $data, fn (Club $c) => $this->access->member($actor, $c), function (Club $c) use ($actor, $id, $data) {
            $t = $this->find($c, $id);
            $this->planned($t);
            $member = $this->access->member($actor, $c);
            abort_if($data['response'] === 'yes' && $member->effectiveStatus() !== 'active', 403, 'Во время паузы нельзя записаться на тренировку.');
            DB::table('training_rsvps')->upsert([['club_id' => $c->id, 'training_id' => $id, 'membership_id' => $member->id, 'response' => $data['response'], 'updated_at' => now(), 'created_at' => now()]], ['training_id', 'membership_id'], ['response', 'updated_at']);
            Audit::record($actor->id, 'training.rsvp', ['training_id' => $id, 'membership_id' => $member->id, 'response' => $data['response']], $c->id);

            return ['training_id' => $id, 'response' => $data['response']];
        });
    }

    public function draft(Club $club, User $actor, string $key, int $id, array $input): array
    {
        $data = Validator::make($input, ['version' => 'required|integer|min:1', 'present_membership_ids' => 'present|array|max:1000', 'present_membership_ids.*' => 'required|integer|min:1|distinct'])->validate();
        $data['present_membership_ids'] = array_map('intval', $data['present_membership_ids']);
        sort($data['present_membership_ids']);
        $data['training_id'] = $id;

        return $this->actions->run($club, $actor, $key, 'training.draft', $data, fn (Club $c) => $this->trainingAccess->attendance($c, $actor, $this->find($c, $id)), function (Club $c) use ($actor, $id, $data) {
            $t = $this->find($c, $id);
            $this->planned($t);
            $this->expected($t, (int) $data['version']);
            foreach ($data['present_membership_ids'] as $memberId) {
                $m = Membership::where('club_id', $c->id)->findOrFail($memberId);
                $already = DB::table('training_rsvps')->where('training_id', $id)->where('membership_id', $memberId)->where('response', 'yes')->exists()
                    || DB::table('training_attendances')->where('training_id', $id)->where('membership_id', $memberId)->where('draft_present', true)->exists();
                abort_unless($m->effectiveStatus() === 'active' || $already, 422, 'Без предварительной записи можно добавить только активного участника.');
            }
            DB::table('training_attendances')->where('club_id', $c->id)->where('training_id', $id)->update(['draft_present' => false, 'updated_at' => now()]);
            foreach ($data['present_membership_ids'] as $memberId) {
                DB::table('training_attendances')->upsert([['club_id' => $c->id, 'training_id' => $id, 'membership_id' => $memberId, 'draft_present' => true, 'created_at' => now(), 'updated_at' => now()]], ['training_id', 'membership_id'], ['draft_present', 'updated_at']);
            }
            DB::table('trainings')->where('id', $id)->update(['version' => $t->version + 1, 'updated_at' => now()]);
            $t = $this->find($c, $id);
            $this->revision($c, $actor, $t, $data['present_membership_ids'], 'draft');
            Audit::record($actor->id, 'training.draft_saved', ['training_id' => $id, 'version' => $t->version], $c->id);

            return $this->result($t);
        });
    }

    public function close(Club $club, User $actor, string $key, int $id, array $input): array
    {
        $data = Validator::make($input, ['version' => 'required|integer|min:1'])->validate();
        $data['training_id'] = $id;
        $authorize = fn (Club $c) => $this->trainingAccess->attendance($c, $actor, $this->find($c, $id));

        return $this->actions->run($club, $actor, $key, 'training.close', $data, $authorize, function (Club $c) use ($actor, $id, $data, $authorize) {
            $t = $this->find($c, $id);
            if ($t->status === 'closed') {
                return $this->result($t);
            } $this->planned($t);
            $this->expected($t, (int) $data['version']);
            abort_if(CarbonImmutable::parse($t->starts_at, 'UTC')->isFuture(), 422, 'Будущую тренировку еще нельзя считать проведенной.');
            $period = $this->calendar->ensure($c, CarbonImmutable::parse($t->starts_at, 'UTC'));
            DB::table('training_attendances')->where('club_id', $c->id)->where('training_id', $id)->update(['confirmed_present' => DB::raw('draft_present'), 'confirmed_at' => now(), 'updated_at' => now()]);
            DB::table('trainings')->where('id', $id)->update(['status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => now(), 'period_id' => $period->id, 'version' => $t->version + 1, 'updated_at' => now()]);
            $t = $this->find($c, $id);
            $present = DB::table('training_attendances')->where('training_id', $id)->where('confirmed_present', true)->pluck('membership_id')->map(fn ($id) => (int) $id)->all();
            foreach ($present as $memberId) {
                $this->billing->attend($c, $actor, $t, $period, $memberId, $authorize);
            }
            $this->revision($c, $actor, $t, $present, 'closed');
            $this->event($c, $actor, $t, 'closed');

            return $this->result($t);
        });
    }

    private function revision(Club $club, User $actor, object $t, array $present, string $kind): void
    {
        DB::table('attendance_revisions')->insert(['club_id' => $club->id, 'training_id' => $t->id, 'training_version' => $t->version, 'present_members' => json_encode($present, JSON_THROW_ON_ERROR), 'actor_id' => $actor->id, 'kind' => $kind, 'reason' => $kind === 'closed' ? 'Закрытие состоявшейся тренировки' : 'Черновик явки', 'created_at' => now()]);
    }

    private function event(Club $club, User $actor, object $t, string $topic, string $reason = ''): void
    {
        Audit::record($actor->id, 'training.'.$topic, ['training_id' => $t->id, 'version' => $t->version, 'reason' => $reason], $club->id);
        $recipients = DB::table('training_rsvps')->where('training_id', $t->id)->where('response', 'yes')->pluck('membership_id')->map(fn ($id) => (int) $id)->all();
        DB::table('training_outbox')->insert(['club_id' => $club->id, 'training_id' => $t->id, 'training_version' => $t->version, 'topic' => 'training.'.$topic, 'payload' => json_encode(['training_id' => $t->id, 'starts_at' => $t->starts_at, 'responsible_membership_id' => $t->responsible_membership_id, 'recipient_membership_ids' => $recipients, 'reason' => $reason], JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public function template(Club $club, User $actor, string $key, array $input, ?int $id = null): array
    {
        $rules = ['title' => 'required|string|min:2|max:120', 'weekdays' => 'required|array|min:1|max:7', 'weekdays.*' => 'required|integer|between:1,7|distinct', 'local_time' => 'required|date_format:H:i', 'responsible_membership_id' => 'required|integer|min:1', 'active' => 'required|boolean'];
        if ($id) {
            $rules['version'] = 'required|integer|min:1';
        } $data = Validator::make($input, $rules)->validate();
        $data['template_id'] = $id;

        return $this->actions->run($club, $actor, $key, 'template.save', $data, fn (Club $c) => $this->access->authorize($actor, $c, 'schedule.manage'), function (Club $c) use ($actor, $id, $data) {
            $this->responsible($c, (int) $data['responsible_membership_id']);
            $version = 1;
            if ($id) {
                $old = DB::table('training_templates')->where('club_id', $c->id)->find($id) ?? abort(404);
                $this->expected($old, (int) $data['version']);
                $version = $old->version + 1;
            }
            $fields = ['title' => $data['title'], 'weekdays' => json_encode(array_map('intval', $data['weekdays']), JSON_THROW_ON_ERROR), 'local_time' => $data['local_time'], 'responsible_membership_id' => $data['responsible_membership_id'], 'active' => (bool) $data['active'], 'version' => $version, 'updated_at' => now()];
            if ($id) {
                DB::table('training_templates')->where('id', $id)->update($fields);
            } else {
                $id = DB::table('training_templates')->insertGetId(['club_id' => $c->id, 'created_at' => now()] + $fields);
            }
            Audit::record($actor->id, 'template.saved', ['template_id' => $id, 'version' => $version], $c->id);

            return ['id' => $id, 'version' => $version];
        });
    }

    public function generate(Club $club, User $actor, string $key, int $id, array $input): array
    {
        $data = Validator::make($input, ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();
        $data['template_id'] = $id;

        return $this->actions->run($club, $actor, $key, 'template.generate', $data, fn (Club $c) => $this->access->authorize($actor, $c, 'schedule.manage'), function (Club $c) use ($actor, $id, $data) {
            $template = DB::table('training_templates')->where('club_id', $c->id)->find($id) ?? abort(404);
            abort_unless($template->active, 409, 'Шаблон выключен.');
            $this->responsible($c, $template->responsible_membership_id);
            $from = CarbonImmutable::parse($data['from'], $c->timezone);
            $to = CarbonImmutable::parse($data['to'], $c->timezone);
            abort_if($from->diffInDays($to) > 366, 422, 'Создавайте расписание частями до одного года за запрос.');
            $weekdays = json_decode($template->weekdays, true, 512, JSON_THROW_ON_ERROR);
            $created = [];
            for ($day = $from; $day->lte($to); $day = $day->addDay()) {
                if (! in_array($day->isoWeekday(), $weekdays, true) || DB::table('trainings')->where('template_id', $id)->where('occurrence_date', $day->toDateString())->exists()) {
                    continue;
                }
                $trainingId = DB::table('trainings')->insertGetId(['club_id' => $c->id, 'template_id' => $id, 'occurrence_date' => $day->toDateString(), 'title' => $template->title, 'starts_at' => $this->calendar->localTime($c, $day->toDateString().'T'.$template->local_time), 'responsible_membership_id' => $template->responsible_membership_id, 'created_at' => now(), 'updated_at' => now()]);
                $this->event($c, $actor, $this->find($c, $trainingId), 'created');
                $created[] = $trainingId;
            }

            return ['created_ids' => $created];
        });
    }
}
