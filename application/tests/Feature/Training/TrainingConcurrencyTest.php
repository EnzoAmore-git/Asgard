<?php

namespace Tests\Feature\Training;

use App\Models\Club;
use App\Models\User;
use App\Services\Clubs\ClubService;
use App\Services\Training\Billing;
use App\Services\Training\Trainings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TrainingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_concurrent_sheets_close_with_one_subscription_for_member_and_period(): void
    {
        Carbon::setTestNow('2026-10-24T12:00:00Z');
        CarbonImmutable::setTestNow('2026-10-24T12:00:00Z');
        try {
            $operator = User::factory()->create(['is_operator' => true]);
            $owner = User::factory()->create();
            $club = app(ClubService::class)->create($operator, $owner, ['name' => 'Параллельные тренировки', 'owner_name' => 'Рагнар', 'timezone' => 'Europe/Moscow', 'currency' => 'RUB', 'period_start_day' => 10]);
            app(ClubService::class)->setPermissions($owner, $club, $club->owner_membership_id, ['schedule.manage', 'finance.manage']);
            app(Billing::class)->configure($club, $owner, (string) Str::uuid(), ['effective_from' => '2026-11-10', 'amount_minor' => '250000', 'reason' => 'Тестовый тариф']);
            $token = basename(app(ClubService::class)->invite($owner, $club)['url']);
            $one = User::factory()->create();
            $two = User::factory()->create();
            $m1 = app(ClubService::class)->join($one, $token, 'Бьорн');
            $m2 = app(ClubService::class)->join($two, $token, 'Тор');
            $t1 = app(Trainings::class)->create($club, $owner, (string) Str::uuid(), ['title' => 'Первая', 'local_start' => '2026-11-11T18:00', 'responsible_membership_id' => $m1->id]);
            $t2 = app(Trainings::class)->create($club, $owner, (string) Str::uuid(), ['title' => 'Вторая', 'local_start' => '2026-11-12T18:00', 'responsible_membership_id' => $m2->id]);
            $t1 = app(Trainings::class)->draft($club, $one, (string) Str::uuid(), $t1['id'], ['version' => 1, 'present_membership_ids' => [$club->owner_membership_id]]);
            $t2 = app(Trainings::class)->draft($club, $two, (string) Str::uuid(), $t2['id'], ['version' => 1, 'present_membership_ids' => [$club->owner_membership_id]]);
            $clubId = $club->id;
            $actor1 = $one->id;
            $actor2 = $two->id;
            $results = Concurrency::driver('process')->run([
                static fn () => TrainingConcurrencyTest::closeWorker($clubId, $actor1, $t1['id'], $t1['version']),
                static fn () => TrainingConcurrencyTest::closeWorker($clubId, $actor2, $t2['id'], $t2['version']),
            ]);
            $this->assertSame(['closed', 'closed'], array_column($results, 'status'));
            $this->assertDatabaseCount('subscription_obligations', 1);
            $this->assertDatabaseCount('financial_events', 1);
            $this->assertDatabaseCount('member_entries', 1);
            $this->assertSame('-250000', (string) DB::table('member_entries')->sum('amount_minor'));
            $this->assertSame(2, DB::table('training_attendances')->where('confirmed_present', true)->count());
            $this->assertSame(2, DB::table('trainings')->where('status', 'closed')->count());
            $this->assertDatabaseCount('money_entries', 0);
            $this->assertDatabaseCount('financial_outbox', 1);
            foreach ($results as $r) {
                $this->assertEqualsCanonicalizing(['id', 'version', 'status'], array_keys($r));
            }
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }

    public static function closeWorker(int $club, int $actor, int $training, int $version): array
    {
        Carbon::setTestNow('2026-11-20T20:00:00Z');
        CarbonImmutable::setTestNow('2026-11-20T20:00:00Z');

        return app(Trainings::class)->close(Club::findOrFail($club), User::findOrFail($actor), (string) Str::uuid(), $training, ['version' => $version]);
    }
}
