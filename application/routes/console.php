<?php

use App\Models\Club;
use App\Models\User;
use App\Services\Clubs\ClubService;
use App\Services\Training\TrainingDemo;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

Artisan::command('asgard:training-demo', function () {
    if (! app()->environment('local')) {
        $this->error('Available only in local environment.');

        return 1;
    }
    $result = app(TrainingDemo::class)->create();
    $this->info('Fictional training club: '.$result['club_id'].'. Local owner user: '.$result['owner_id'].'. No real money or provider login.');

    return 0;
})->purpose('Create a local fictional training and subscription example');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('asgard:operator {user}', function () {
    $user = User::findOrFail($this->argument('user'));
    $user->forceFill(['is_operator' => true])->save();
    $this->info('Оператор назначен. Финансовых прав клубов это не дает.');
})->purpose('Назначить оператора платформы через доверенную консоль');

Artisan::command('asgard:club {name} {--operator=} {--owner=} {--owner-name=} {--timezone=} {--currency=} {--period-day=10}', function () {
    $operator = User::findOrFail($this->option('operator'));
    $owner = User::findOrFail($this->option('owner'));
    $club = app(ClubService::class)->create($operator, $owner, [
        'name' => $this->argument('name'), 'owner_name' => $this->option('owner-name'),
        'timezone' => $this->option('timezone'), 'currency' => $this->option('currency'),
        'period_start_day' => $this->option('period-day'),
    ]);
    $this->info('Создан клуб #'.$club->id.'. Финансовые записи не создавались.');
})->purpose('Вручную создать клуб и назначить первого владельца');

Artisan::command('asgard:local-access {user}', function () {
    if (! app()->environment('local')) {
        $this->error('Доступно только в локальной среде.');

        return 1;
    }
    $user = User::findOrFail($this->argument('user'));
    $token = Str::random(64);
    Cache::put('asgard.local-access.'.hash('sha256', $token), $user->id, now()->addMinutes(5));
    $this->line(url('/local-access/'.$token));
})->purpose('Выдать одноразовую ссылку локального доступа на 5 минут');

Artisan::command('asgard:demo', function () {
    if (! app()->environment('local')) {
        $this->error('Демонстрационные данные разрешены только локально.');

        return 1;
    }
    $operator = User::firstOrCreate(['email' => 'operator@asgard.example.invalid'], ['name' => 'Локальный оператор']);
    $operator->forceFill(['is_operator' => true])->save();
    $owner = User::firstOrCreate(['email' => 'ragnar@asgard.example.invalid'], ['name' => 'Рагнар']);
    $member = User::firstOrCreate(['email' => 'bjorn@asgard.example.invalid'], ['name' => 'Бьорн']);
    $services = app(ClubService::class);
    $club = Club::where('name', 'Асгард · проверка')->first();
    if (! $club) {
        $club = $services->create($operator, $owner, ['name' => 'Асгард · проверка', 'owner_name' => 'Рагнар', 'timezone' => 'Europe/Moscow', 'currency' => 'RUB', 'period_start_day' => 10]);
        $services->setPermissions($owner, $club, $club->owner_membership_id, ['members.manage', 'schedule.manage']);
        $invite = $services->invite($owner, $club);
        $services->join($member, basename($invite['url']), 'Бьорн');
    }
    $this->line('Локальные вымышленные данные. Оператор #'.$operator->id.', владелец #'.$owner->id.', участник #'.$member->id);
})->purpose('Подготовить локальные вымышленные аккаунты и клуб без денежных операций');
