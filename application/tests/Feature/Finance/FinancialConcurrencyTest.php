<?php

namespace Tests\Feature\Finance;

use App\Models\Club;
use App\Models\User;
use App\Services\Clubs\ClubAccess;
use App\Services\Clubs\ClubService;
use App\Services\Finance\Journal;
use App\Services\Finance\OpeningBalances;
use App\Services\Finance\Posting;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FinancialConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private Club $club;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $operator = User::factory()->create(['is_operator' => true]);
        $this->owner = User::factory()->create();
        $this->club = app(ClubService::class)->create($operator, $this->owner, ['name' => 'Финансовая проверка', 'owner_name' => 'Казначей', 'timezone' => 'Europe/Moscow', 'currency' => 'RUB', 'period_start_day' => 10]);
        app(ClubService::class)->setPermissions($this->owner, $this->club, $this->club->owner_membership_id, ['finance.manage']);
    }

    private function input(string $amount, ?int $previous = null): array
    {
        return ['target' => 'card', 'membership_id' => null, 'amount_minor' => $amount, 'currency' => 'RUB', 'date' => '2026-10-24', 'reason' => 'Сверенный стартовый остаток', 'previous_event_id' => $previous];
    }

    public function test_concurrent_identical_requests_and_same_source_with_new_key_post_once(): void
    {
        $clubId = $this->club->id;
        $actorId = $this->owner->id;
        $data = $this->input('4250000');
        $key = (string) Str::uuid();
        $results = Concurrency::driver('process')->run([
            static fn () => app(OpeningBalances::class)->set(Club::findOrFail($clubId), User::findOrFail($actorId), $key, $data)->id,
            static fn () => app(OpeningBalances::class)->set(Club::findOrFail($clubId), User::findOrFail($actorId), $key, $data)->id,
            static fn () => app(OpeningBalances::class)->set(Club::findOrFail($clubId), User::findOrFail($actorId), (string) Str::uuid(), $data)->id,
        ]);
        $this->assertCount(1, array_unique($results));
        $this->assertDatabaseCount('financial_events', 1);
        $this->assertDatabaseCount('money_entries', 1);
        $this->assertDatabaseCount('financial_outbox', 1);
        $this->assertDatabaseCount('financial_requests', 2);
        $this->assertSame(1, DB::table('audit_events')->where('event', 'finance.posted')->count());
    }

    public function test_concurrent_opening_corrections_do_not_overwrite_each_other(): void
    {
        $first = app(OpeningBalances::class)->set($this->club, $this->owner, (string) Str::uuid(), $this->input('10000'));
        $clubId = $this->club->id;
        $actorId = $this->owner->id;
        $one = $this->input('20000', $first->id);
        $two = $this->input('30000', $first->id);
        $results = Concurrency::driver('process')->run([
            static function () use ($clubId, $actorId, $one) {
                try {
                    app(OpeningBalances::class)->set(Club::findOrFail($clubId), User::findOrFail($actorId), (string) Str::uuid(), $one);

                    return 200;
                } catch (HttpException $e) {
                    return $e->getStatusCode();
                }
            },
            static function () use ($clubId, $actorId, $two) {
                try {
                    app(OpeningBalances::class)->set(Club::findOrFail($clubId), User::findOrFail($actorId), (string) Str::uuid(), $two);

                    return 200;
                } catch (HttpException $e) {
                    return $e->getStatusCode();
                }
            },
        ]);
        sort($results);
        $this->assertSame([200, 409], $results);
        $this->assertDatabaseCount('financial_events', 2);
        $this->assertContains((string) DB::table('money_entries')->sum('amount_minor'), ['20000', '30000']);
    }

    public function test_concurrent_internal_transfers_cannot_exceed_custody_balance(): void
    {
        $clubId = $this->club->id;
        $actorId = $this->owner->id;
        $memberId = $this->club->owner_membership_id;
        app(OpeningBalances::class)->set($this->club, $this->owner, (string) Str::uuid(), array_replace($this->input('200000'), ['target' => 'custody', 'membership_id' => $memberId]));
        $results = Concurrency::driver('process')->run([
            static fn () => FinancialConcurrencyTest::transferOnce($clubId, $actorId, $memberId, 'one'),
            static fn () => FinancialConcurrencyTest::transferOnce($clubId, $actorId, $memberId, 'two'),
        ]);
        sort($results);
        $this->assertSame([200, 422], $results);
        $this->assertSame('200000', (string) DB::table('money_entries')->sum('amount_minor'));
        $this->assertSame('50000', (string) DB::table('money_entries')->where('location', 'custody')->sum('amount_minor'));
        $this->assertDatabaseCount('financial_events', 2);
    }

    public static function transferOnce(int $clubId, int $actorId, int $memberId, string $source): int
    {
        $actor = User::findOrFail($actorId);
        try {
            app(Journal::class)->post(Club::findOrFail($clubId), $actor, (string) Str::uuid(), 'test_transfer', $source,
                ['currency' => 'RUB', 'occurred_at' => '2026-10-24T10:00:00Z', 'reason' => 'Проверка лимита передачи'],
                static fn (Club $club) => app(ClubAccess::class)->authorize($actor, $club, 'finance.manage'),
                static fn () => Posting::transfer($memberId, 150000));

            return 200;
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }
}
