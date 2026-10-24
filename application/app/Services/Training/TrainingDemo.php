<?php

namespace App\Services\Training;

use App\Models\Club;
use App\Models\Membership;
use App\Models\User;
use App\Services\Clubs\ClubService;
use App\Services\Finance\OpeningBalances;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TrainingDemo
{
    public function create(): array
    {
        abort_unless(app()->environment('local'), 403);
        $real = CarbonImmutable::now();
        $oldCarbon = Carbon::getTestNow();
        $oldImmutable = CarbonImmutable::getTestNow();
        try {
            return DB::transaction(function () use ($real) {
                $owner = User::firstOrCreate(['email' => 'ragnar@asgard.example.invalid'], ['name' => 'Рагнар']);
                if ($club = Club::where('name', 'Асгард · тренировки')->first()) {
                    return ['club_id' => $club->id, 'owner_id' => $owner->id];
                }
                $operator = User::firstOrCreate(['email' => 'operator@asgard.example.invalid'], ['name' => 'Локальный оператор']);
                $operator->forceFill(['is_operator' => true])->save();
                $services = app(ClubService::class);
                $club = $services->create($operator, $owner, ['name' => 'Асгард · тренировки', 'owner_name' => 'Рагнар', 'timezone' => 'Europe/Moscow', 'currency' => 'RUB', 'period_start_day' => 10]);
                $services->setPermissions($owner, $club, $club->owner_membership_id, ['members.manage', 'schedule.manage', 'finance.manage']);
                $token = basename($services->invite($owner, $club)['url']);
                $members = ['Рагнар' => Membership::findOrFail($club->owner_membership_id)];
                foreach (['bjorn' => 'Бьорн', 'thor' => 'Тор', 'erik' => 'Эрик'] as $email => $name) {
                    $user = User::firstOrCreate(['email' => $email.'@asgard.example.invalid'], ['name' => $name]);
                    $members[$name] = $services->join($user, $token, $name);
                }
                $calendar = app(Calendar::class);
                $start = $calendar->period($club, $real)['starts_on'];
                $boundary = CarbonImmutable::parse($start, $club->timezone);
                $setup = $boundary->subDay()->utc();
                Carbon::setTestNow($setup);
                CarbonImmutable::setTestNow($setup);
                app(Billing::class)->configure($club, $owner, (string) Str::uuid(), ['effective_from' => $start, 'amount_minor' => '250000', 'reason' => 'Вымышленный учебный тариф']);
                app(Billing::class)->exemption($club, $owner, (string) Str::uuid(), $members['Тор']->id, ['effective_from' => $start, 'exempt' => true, 'reason' => 'Учебный пример освобождения']);
                foreach ([['card', null, '3000000'], ['member', $members['Рагнар']->id, '-250000'], ['member', $members['Бьорн']->id, '100000']] as [$target,$member,$amount]) {
                    app(OpeningBalances::class)->set($club, $owner, (string) Str::uuid(), ['target' => $target, 'membership_id' => $member, 'amount_minor' => $amount, 'currency' => 'RUB', 'date' => $setup->setTimezone($club->timezone)->toDateString(), 'previous_event_id' => null, 'reason' => 'Вымышленные начальные данные']);
                }
                Carbon::setTestNow($real);
                CarbonImmutable::setTestNow($real);
                $past = $real->subHours(2);
                if ($past->lt($boundary)) {
                    $past = $boundary;
                }
                $trainings = app(Trainings::class);
                $closed = $trainings->create($club, $owner, (string) Str::uuid(), ['title' => 'Учебная тренировка · проведенная', 'local_start' => $past->setTimezone($club->timezone)->format('Y-m-d\TH:i'), 'responsible_membership_id' => $club->owner_membership_id]);
                $closed = $trainings->draft($club, $owner, (string) Str::uuid(), $closed['id'], ['version' => $closed['version'], 'present_membership_ids' => array_map(fn ($m) => $m->id, array_values($members))]);
                $trainings->close($club, $owner, (string) Str::uuid(), $closed['id'], ['version' => $closed['version']]);
                $trainings->create($club, $owner, (string) Str::uuid(), ['title' => 'Учебная тренировка · можно записаться', 'local_start' => $real->addDay()->setTimezone($club->timezone)->setTime(18, 0)->format('Y-m-d\TH:i'), 'responsible_membership_id' => $members['Бьорн']->id]);

                return ['club_id' => $club->id, 'owner_id' => $owner->id];
            });
        } finally {
            Carbon::setTestNow($oldCarbon);
            CarbonImmutable::setTestNow($oldImmutable);
        }
    }
}
