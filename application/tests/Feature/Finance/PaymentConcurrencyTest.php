<?php

namespace Tests\Feature\Finance;

use App\Models\Club;
use App\Models\User;
use App\Services\Clubs\ClubService;
use App\Services\Finance\OpeningBalances;
use App\Services\Finance\Payments;
use App\Services\Finance\Treasury;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PaymentConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private Club $club;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->club = app(ClubService::class)->create(User::factory()->create(['is_operator' => true]), $this->owner, ['name' => 'Конкурентные оплаты', 'owner_name' => 'Казначей', 'timezone' => 'Europe/Moscow', 'currency' => 'RUB', 'period_start_day' => 10]);
        app(ClubService::class)->setPermissions($this->owner, $this->club, $this->club->owner_membership_id, ['finance.manage', 'cash.receive']);
        config(['finance.duplicate_window_days' => 3]);
    }

    private function opening(string $target, string $amount, ?int $member = null): void
    {
        app(OpeningBalances::class)->set($this->club, $this->owner, (string) Str::uuid(), ['target' => $target, 'amount_minor' => $amount, 'membership_id' => $member, 'previous_event_id' => null, 'currency' => 'RUB', 'date' => '2026-10-24', 'reason' => 'Тестовый старт']);
    }

    public static function fact(string $amount): array
    {
        return ['amount_minor' => $amount, 'currency' => 'RUB', 'date' => '2026-10-24', 'reason' => 'Проверено по факту'];
    }

    public function test_two_processes_confirm_one_claim_with_different_keys_once(): void
    {
        $claim = app(Payments::class)->claim($this->club, $this->owner, (string) Str::uuid(), self::fact('250000') + ['method' => 'card']);
        $club = $this->club->id;
        $owner = $this->owner->id;
        $id = $claim['id'];
        $results = Concurrency::driver('process')->run([static fn () => self::review($club, $owner, $id), static fn () => self::review($club, $owner, $id)]);
        $this->assertSame($results[0]['receipt_id'], $results[1]['receipt_id']);
        $this->assertDatabaseCount('payment_receipts', 1);
        $this->assertDatabaseCount('financial_events', 1);
        $this->assertDatabaseCount('money_entries', 1);
        $this->assertDatabaseCount('member_entries', 1);
    }

    public static function review(int $club, int $owner, int $id): array
    {
        config(['finance.duplicate_window_days' => 3]);

        return app(Payments::class)->review(Club::findOrFail($club), User::findOrFail($owner), (string) Str::uuid(), $id, ['decision' => 'confirm', 'reason' => 'Подтверждено в банке', 'duplicates_acknowledged' => false]);
    }

    public function test_two_processes_transfer_no_more_than_current_custody(): void
    {
        $this->opening('custody', '500000', $this->club->owner_membership_id);
        $club = $this->club->id;
        $actor = $this->owner->id;
        $holder = $this->club->owner_membership_id;
        $results = Concurrency::driver('process')->run([static fn () => self::transfer($club, $actor, $holder), static fn () => self::transfer($club, $actor, $holder)]);
        sort($results);
        $this->assertSame([200, 422], $results);
        $this->assertSame('200000', (string) DB::table('money_entries')->where('location', 'custody')->sum('amount_minor'));
        $this->assertSame('500000', (string) DB::table('money_entries')->sum('amount_minor'));
    }

    public static function transfer(int $club, int $actor, int $holder): int
    {
        try {
            app(Treasury::class)->transfer(Club::findOrFail($club), User::findOrFail($actor), (string) Str::uuid(), self::fact('300000') + ['custodian_membership_id' => $holder]);

            return 200;
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }

    public function test_two_refunds_cannot_spend_same_overpayment(): void
    {
        $this->opening('member', '-100000', $this->club->owner_membership_id);
        $r = app(Payments::class)->receipt($this->club, $this->owner, (string) Str::uuid(), self::fact('200000') + ['method' => 'card', 'membership_id' => $this->club->owner_membership_id, 'duplicates_acknowledged' => false]);
        $club = $this->club->id;
        $actor = $this->owner->id;
        $id = $r['receipt_id'];
        $results = Concurrency::driver('process')->run([static fn () => self::refund($club, $actor, $id), static fn () => self::refund($club, $actor, $id)]);
        sort($results);
        $this->assertSame([200, 409], $results);
        $this->assertSame('0', (string) DB::table('member_entries')->sum('amount_minor'));
        $this->assertSame('100000', (string) DB::table('money_entries')->sum('amount_minor'));
    }

    public static function refund(int $club, int $actor, int $id): int
    {
        try {
            app(Payments::class)->refund(Club::findOrFail($club), User::findOrFail($actor), (string) Str::uuid(), $id, self::fact('100000') + ['version' => 1]);

            return 200;
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }

    public function test_refunds_from_different_receipts_check_same_current_member_balance(): void
    {
        $this->opening('member', '-150000', $this->club->owner_membership_id);
        $d = self::fact('100000') + ['method' => 'card', 'membership_id' => $this->club->owner_membership_id, 'duplicates_acknowledged' => true];
        $one = app(Payments::class)->receipt($this->club, $this->owner, (string) Str::uuid(), $d);
        $two = app(Payments::class)->receipt($this->club, $this->owner, (string) Str::uuid(), $d);
        $club = $this->club->id;
        $actor = $this->owner->id;
        $results = Concurrency::driver('process')->run([static fn () => self::refundAmount($club, $actor, $one['receipt_id']), static fn () => self::refundAmount($club, $actor, $two['receipt_id'])]);
        sort($results);
        $this->assertSame([200, 422], $results);
        $this->assertSame('0', (string) DB::table('member_entries')->sum('amount_minor'));
    }

    public static function refundAmount(int $club, int $actor, int $id): int
    {
        try {
            app(Payments::class)->refund(Club::findOrFail($club), User::findOrFail($actor), (string) Str::uuid(), $id, self::fact('50000') + ['version' => 1]);

            return 200;
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }

    public function test_two_bank_cancellations_cannot_cancel_same_source_twice(): void
    {
        $d = self::fact('100000') + ['method' => 'card', 'membership_id' => $this->club->owner_membership_id, 'duplicates_acknowledged' => false];
        $r = app(Payments::class)->receipt($this->club, $this->owner, (string) Str::uuid(), $d);
        $club = $this->club->id;
        $actor = $this->owner->id;
        $id = $r['receipt_id'];
        $results = Concurrency::driver('process')->run([static fn () => self::cancelAmount($club, $actor, $id), static fn () => self::cancelAmount($club, $actor, $id)]);
        sort($results);
        $this->assertSame([200, 409], $results);
        $this->assertSame('50000', (string) DB::table('member_entries')->sum('amount_minor'));
        $this->assertDatabaseHas('payment_receipts', ['id' => $id, 'cancelled_minor' => 50000]);
    }

    public static function cancelAmount(int $club, int $actor, int $id): int
    {
        try {
            app(Payments::class)->bankCancel(Club::findOrFail($club), User::findOrFail($actor), (string) Str::uuid(), $id, self::fact('50000') + ['version' => 1]);

            return 200;
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }
}
