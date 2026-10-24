<?php

namespace App\Services\Finance;

use App\Models\Club;
use App\Models\User;
use App\Services\Clubs\ClubService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PaymentDemo
{
    public function create(): array
    {
        abort_unless(app()->environment('local'), 403);

        return DB::transaction(function () {
            $owner = User::firstOrCreate(['email' => 'ragnar@asgard.example.invalid'], ['name' => 'Рагнар']);
            if ($club = Club::where('name', 'Асгард · оплаты')->first()) {
                return ['club_id' => $club->id, 'owner_id' => $owner->id];
            }
            $operator = User::firstOrCreate(['email' => 'operator@asgard.example.invalid'], ['name' => 'Локальный оператор']);
            $operator->forceFill(['is_operator' => true])->save();
            $service = app(ClubService::class);
            $club = $service->create($operator, $owner, ['name' => 'Асгард · оплаты', 'owner_name' => 'Рагнар', 'timezone' => 'Europe/Moscow', 'currency' => 'RUB', 'period_start_day' => 10]);
            $service->setPermissions($owner, $club, $club->owner_membership_id, ['finance.manage', 'cash.receive']);
            $user = User::firstOrCreate(['email' => 'bjorn@asgard.example.invalid'], ['name' => 'Бьорн']);
            $token = basename($service->invite($owner, $club)['url']);
            $m = $service->join($user, $token, 'Бьорн');
            $service->setPermissions($owner, $club, $m->id, ['cash.receive']);
            foreach ([['card', null, '3000000'], ['member', $club->owner_membership_id, '-1500000']] as [$target,$member,$amount]) {
                app(OpeningBalances::class)->set($club, $owner, (string) Str::uuid(), ['target' => $target, 'membership_id' => $member, 'amount_minor' => $amount, 'currency' => 'RUB', 'date' => now($club->timezone)->toDateString(), 'reason' => 'Учебные начальные данные', 'previous_event_id' => null]);
            }

            return ['club_id' => $club->id, 'owner_id' => $owner->id];
        });
    }
}
