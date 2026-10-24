<?php

namespace Tests\Feature\Finance;

use App\Models\Club;
use App\Models\Membership;
use App\Models\User;
use App\Services\Clubs\ClubAccess;
use App\Services\Clubs\ClubService;
use App\Services\Finance\FinancialRead;
use App\Services\Finance\Journal;
use App\Services\Finance\Money;
use App\Services\Finance\OpeningBalances;
use App\Services\Finance\Posting;
use App\Services\Finance\ProtectJournal;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinancialFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private User $owner;

    private User $operator;

    private Membership $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = User::factory()->create(['is_operator' => true]);
        $this->owner = User::factory()->create();
        $this->club = app(ClubService::class)->create($this->operator, $this->owner, [
            'name' => 'Асгард', 'owner_name' => 'Рагнар', 'timezone' => 'Europe/Moscow', 'currency' => 'RUB', 'period_start_day' => 10,
        ]);
        $this->member = Membership::findOrFail($this->club->owner_membership_id);
        app(ClubService::class)->setPermissions($this->owner, $this->club, $this->member->id, ['finance.manage']);
    }

    private function input(string $target, string $amount, ?int $member = null, ?int $previous = null): array
    {
        return ['target' => $target, 'amount_minor' => $amount, 'membership_id' => $member, 'previous_event_id' => $previous,
            'currency' => 'RUB', 'date' => '2026-10-24', 'reason' => 'Перенос проверенных начальных данных'];
    }

    private function opening(string $target, string $amount, ?int $member = null, ?int $previous = null): object
    {
        return app(OpeningBalances::class)->set($this->club, $this->owner, (string) Str::uuid(), $this->input($target, $amount, $member, $previous));
    }

    private function record(Posting $draft, string $source, ?string $key = null, array $parameters = []): object
    {
        return app(Journal::class)->post($this->club, $this->owner, $key ?? (string) Str::uuid(), 'test_fact', $source,
            $parameters + ['currency' => 'RUB', 'occurred_at' => '2026-10-24T10:00:00Z', 'reason' => 'Подтвержденный тестовый факт',
                'members' => $draft->members, 'money' => $draft->money, 'type' => $draft->type, 'related' => $draft->related],
            fn (Club $club) => app(ClubAccess::class)->authorize($this->owner, $club, 'finance.manage'), fn () => $draft);
    }

    private function addMember(string $name): Membership
    {
        $token = basename(app(ClubService::class)->invite($this->owner, $this->club)['url']);

        return app(ClubService::class)->join(User::factory()->create(), $token, $name);
    }

    private function summary(): array
    {
        return app(FinancialRead::class)->summary($this->club, $this->owner);
    }

    private function balance(Membership $m): string
    {
        return app(FinancialRead::class)->own($this->club, User::findOrFail($m->user_id))['balance_minor'];
    }

    public function test_registration_starts_at_zero_and_does_not_create_financial_events(): void
    {
        foreach (['Бьорн', 'Тор', 'Эрик', 'Ивар', 'Олаф'] as $name) {
            $this->assertSame('0', $this->balance($this->addMember($name)));
        }
        $this->assertDatabaseCount('financial_events', 0);
        $this->assertSame('0', $this->summary()['total_minor']);
    }

    public function test_documented_personal_balances_and_treasury_are_independent(): void
    {
        $bjorn = $this->addMember('Бьорн');
        $thor = $this->addMember('Тор');
        $erik = $this->addMember('Эрик');
        $this->opening('card', '3000000');
        $this->opening('member', '-250000', $this->member->id);
        $this->opening('member', '100000', $bjorn->id);
        $this->opening('member', '0', $thor->id);
        foreach ([$this->member, $bjorn, $erik] as $member) {
            $this->record(Posting::charge($member->id, 250000), 'charge:'.$member->id);
        }
        $this->assertSame('-500000', $this->balance($this->member));
        $this->assertSame('-150000', $this->balance($bjorn));
        $this->assertSame('0', $this->balance($thor));
        $this->assertSame('-250000', $this->balance($erik));
        $this->assertSame('3000000', $this->summary()['total_minor']);
        $this->assertSame('900000', $this->summary()['debt_minor']);
        $this->assertSame(3, DB::table('financial_events')->where('type', 'charge')->count());
    }

    public function test_opening_is_not_income_and_paid_expense_does_not_change_personal_balance(): void
    {
        $this->opening('card', '4250000');
        $this->record(Posting::receipt($this->member->id, 250000), 'receipt');
        $this->record(Posting::expense(1500000), 'rent');
        $this->assertSame('3000000', $this->summary()['card_minor']);
        $this->assertSame('250000', $this->balance($this->member));
        $income = DB::table('money_entries as m')->join('financial_events as e', 'e.id', '=', 'm.event_id')->whereIn('e.type', ['card_receipt', 'cash_receipt'])->sum('m.amount_minor');
        $this->assertSame('250000', (string) $income);
        $this->assertDatabaseCount('financial_events', 3);
    }

    public function test_one_receipt_covers_six_months_without_rewriting_charges(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->record(Posting::charge($this->member->id, 250000), 'month:'.$i);
        }
        $this->record(Posting::receipt($this->member->id, 1500000), 'receipt');
        $this->assertSame('0', $this->balance($this->member));
        $this->assertDatabaseCount('financial_events', 7);
        $this->assertSame('1500000', $this->summary()['card_minor']);
    }

    public function test_cash_transfer_preserves_total_money_and_does_not_credit_member_twice(): void
    {
        $holder = $this->addMember('Тренер');
        $this->opening('card', '3000000');
        $this->record(Posting::receipt($this->member->id, 500000, $holder->id), 'cash');
        $this->assertSame('3500000', $this->summary()['total_minor']);
        $this->record(Posting::transfer($holder->id, 300000), 'transfer');
        $s = $this->summary();
        $this->assertSame('3300000', $s['card_minor']);
        $this->assertSame('200000', $s['custody_minor']);
        $this->assertSame('3500000', $s['total_minor']);
        $this->assertSame('500000', $this->balance($this->member));
        $this->assertSame('200000', app(FinancialRead::class)->own($this->club, User::findOrFail($holder->user_id))['custody_minor']);
    }

    public function test_charge_compensation_preserves_real_receipt_and_original_history(): void
    {
        $this->opening('member', '-250000', $this->member->id);
        $charge = $this->record(Posting::charge($this->member->id, 250000), 'charge');
        $receipt = $this->record(Posting::receipt($this->member->id, 600000), 'receipt');
        $reversal = $this->record(Posting::reverseCharge($this->member->id, 250000, $charge->id), 'reversal');
        $this->assertSame('350000', $this->balance($this->member));
        $this->assertSame('600000', $this->summary()['card_minor']);
        $this->assertSame($charge->id, $reversal->related_event_id);
        $this->assertDatabaseHas('financial_events', ['id' => $receipt->id, 'type' => 'card_receipt']);
        $this->assertDatabaseHas('member_entries', ['event_id' => $charge->id, 'amount_minor' => -250000]);
    }

    public function test_opening_correction_changes_only_opening_portion_and_retains_previous_event(): void
    {
        $first = $this->opening('member', '-250000', $this->member->id);
        $this->record(Posting::receipt($this->member->id, 600000), 'payment');
        $next = $this->opening('member', '-150000', $this->member->id, $first->id);
        $this->assertSame('450000', $this->balance($this->member));
        $this->assertSame('600000', $this->summary()['card_minor']);
        $this->assertSame($first->id, $next->related_event_id);
        $this->assertDatabaseHas('financial_events', ['id' => $first->id, 'opening_value_minor' => -250000]);
        $this->assertDatabaseHas('member_entries', ['event_id' => $next->id, 'amount_minor' => 100000]);
    }

    public function test_opening_zero_is_saved_and_treasury_correction_is_not_income(): void
    {
        $zero = $this->opening('member', '0', $this->member->id);
        $this->assertDatabaseHas('member_entries', ['event_id' => $zero->id, 'amount_minor' => 0]);
        $old = $this->opening('card', '4250000');
        $new = $this->opening('card', '3000000', null, $old->id);
        $this->assertSame('3000000', $this->summary()['total_minor']);
        $this->assertDatabaseHas('money_entries', ['event_id' => $new->id, 'amount_minor' => -1250000]);
        $this->assertSame(0, DB::table('financial_events')->where('type', 'card_receipt')->count());
    }

    public function test_lost_response_retry_and_new_key_same_source_return_original_result(): void
    {
        $data = $this->input('card', '4250000');
        $key = (string) Str::uuid();
        $url = '/api/clubs/'.$this->club->id.'/finance/opening';
        $first = $this->actingAs($this->owner)->withHeader('Idempotency-Key', $key)->postJson($url, $data)->assertOk()->json('event_id');
        $this->postJson($url, $data)->assertOk()->assertJsonPath('event_id', $first);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, $data)->assertOk()->assertJsonPath('event_id', $first);
        $this->assertDatabaseCount('financial_events', 1);
        $this->assertDatabaseCount('money_entries', 1);
        $this->assertDatabaseCount('financial_outbox', 1);
        $this->assertDatabaseCount('financial_requests', 2);
        $this->assertSame(1, DB::table('audit_events')->where('event', 'finance.posted')->count());
        $this->withHeader('Idempotency-Key', $key)->postJson($url, array_replace($data, ['amount_minor' => '1']))->assertConflict();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, array_replace($data, ['amount_minor' => '1']))->assertConflict();
        $this->assertSame('4250000', $this->summary()['card_minor']);
    }

    public function test_stale_opening_correction_is_rejected_without_replacing_newer_data(): void
    {
        $first = $this->opening('card', '10000');
        $this->opening('card', '20000', null, $first->id);
        $this->actingAs($this->owner)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/clubs/'.$this->club->id.'/finance/opening', $this->input('card', '30000', null, $first->id))->assertConflict();
        $this->assertSame('20000', $this->summary()['card_minor']);
        $this->assertDatabaseCount('financial_events', 2);
    }

    public function test_money_is_exact_beyond_javascript_safe_integer_range(): void
    {
        $this->opening('card', '9007199254740993');
        $this->actingAs($this->owner)->getJson('/api/clubs/'.$this->club->id.'/finance/summary')->assertOk()->assertJsonPath('card_minor', '9007199254740993');
        $this->assertSame(2, Money::scale('RUB'));
        $this->assertSame(0, Money::scale('JPY'));
        $this->assertSame(3, Money::scale('KWD'));
    }

    public function test_float_fractional_or_overflow_amounts_and_wrong_currency_are_rejected(): void
    {
        $url = '/api/clubs/'.$this->club->id.'/finance/opening';
        foreach ([1.5, '1.5', '01', '-0', '9223372036854775808'] as $amount) {
            $this->actingAs($this->owner)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, array_replace($this->input('card', '1'), ['amount_minor' => $amount]))->assertUnprocessable();
        }
        $this->postJson($url, array_replace($this->input('card', '1'), ['currency' => 'USD']))->assertUnprocessable();
        $this->assertDatabaseCount('financial_events', 0);
        $this->opening('card', (string) PHP_INT_MAX);
        try {
            $this->record(Posting::receipt($this->member->id, 1), 'overflow');
            $this->fail('Overflow accepted');
        } catch (ValidationException) {
            $this->assertDatabaseCount('financial_events', 1);
        }
    }

    public function test_failure_after_entries_audit_and_outbox_rolls_everything_back(): void
    {
        $fail = true;
        DB::listen(function (QueryExecuted $query) use (&$fail) {
            if ($fail && str_starts_with($query->sql, 'insert into `financial_outbox`')) {
                $fail = false;
                throw new \RuntimeException('Simulated interrupted posting');
            }
        });
        $key = (string) Str::uuid();
        $params = ['currency' => 'RUB', 'occurred_at' => '2026-10-24T10:00:00Z', 'reason' => 'Проверка полного отката'];
        $prepare = function () {
            DB::table('clubs')->where('id', $this->club->id)->update(['name' => 'Измененное основание']);

            return Posting::receipt($this->member->id, 250000);
        };
        $auth = fn (Club $club) => app(ClubAccess::class)->authorize($this->owner, $club, 'finance.manage');
        try {
            app(Journal::class)->post($this->club, $this->owner, $key, 'test_source', 'rollback', $params, $auth, $prepare);
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated interrupted posting', $e->getMessage());
        }
        foreach (['financial_events', 'member_entries', 'money_entries', 'financial_requests', 'financial_outbox'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame('Асгард', $this->club->fresh()->name);
        $this->assertSame(0, DB::table('audit_events')->where('event', 'finance.posted')->count());
        app(Journal::class)->post($this->club, $this->owner, $key, 'test_source', 'rollback', $params, $auth, $prepare);
        $this->assertSame('Измененное основание', $this->club->fresh()->name);
        $this->assertSame('250000', $this->balance($this->member));
        $this->assertDatabaseCount('financial_events', 1);
    }

    public function test_posted_rows_cannot_be_updated_deleted_replaced_or_upserted_by_normal_application(): void
    {
        $event = $this->opening('member', '-250000', $this->member->id);
        $this->opening('card', '3000000');
        foreach (['financial_events', 'member_entries', 'money_entries', 'financial_requests'] as $table) {
            foreach (['update', 'delete'] as $action) {
                try {
                    $query = DB::table($table);
                    $action === 'update' ? $query->update(['id' => 987]) : $query->delete();
                    $this->fail('Mutable posted history');
                } catch (\LogicException) {
                    $this->assertTrue(true);
                }
            }
        }
        $this->assertDatabaseHas('financial_events', ['id' => $event->id]);
        $this->assertSame('-250000', $this->balance($this->member));
        foreach (['REPLACE INTO money_entries VALUES (1)', 'WITH posted AS (SELECT 1) UPDATE financial_events SET reason = ?', 'INSERT INTO member_entries VALUES (1) ON DUPLICATE KEY UPDATE amount_minor = 1'] as $sql) {
            try {
                ProtectJournal::check($sql);
                $this->fail('Mutable query allowed');
            } catch (\LogicException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_owner_manager_operator_and_cash_receiver_do_not_gain_general_financial_access(): void
    {
        $cash = $this->addMember('Получатель');
        $cashUser = User::findOrFail($cash->user_id);
        app(ClubService::class)->setPermissions($this->owner, $this->club, $cash->id, ['cash.receive', 'members.manage']);
        $this->opening('member', '-250000', $this->member->id);
        $this->opening('custody', '500000', $cash->id);
        app(ClubService::class)->setPermissions($this->owner, $this->club, $this->member->id, []);
        foreach ([$this->owner, $cashUser] as $actor) {
            foreach (['summary', 'members', 'journal'] as $path) {
                $this->actingAs($actor)->getJson('/api/clubs/'.$this->club->id.'/finance/'.$path)->assertForbidden();
            }
            $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/clubs/'.$this->club->id.'/finance/opening', $this->input('card', '1'))->assertForbidden();
        }
        $this->actingAs($cashUser)->getJson('/api/clubs/'.$this->club->id.'/finance/me')->assertOk()->assertJsonPath('balance_minor', '0')->assertJsonPath('custody_minor', '500000')->assertJsonMissingPath('card_minor')->assertJsonCount(1, 'cash_history')->assertJsonPath('cash_history.0.amount_minor', '500000')->assertJsonCount(0, 'history');
        $this->actingAs($this->operator)->getJson('/api/clubs/'.$this->club->id.'/finance/summary')->assertNotFound();
        $this->assertDatabaseCount('financial_events', 2);
    }

    public function test_foreign_member_and_foreign_event_cannot_be_linked_in_this_club(): void
    {
        $other = app(ClubService::class)->create($this->operator, User::factory()->create(), ['name' => 'Чужой клуб', 'owner_name' => 'Другой', 'timezone' => 'Europe/Moscow', 'currency' => 'RUB', 'period_start_day' => 10]);
        $event = $this->opening('card', '10000');
        $this->actingAs($this->owner)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/clubs/'.$this->club->id.'/finance/opening', $this->input('member', '1', $other->owner_membership_id))->assertNotFound();
        $this->getJson('/api/clubs/'.$other->id.'/finance/me')->assertNotFound();
        foreach (['summary', 'members', 'journal'] as $path) {
            $this->getJson('/api/clubs/'.$other->id.'/finance/'.$path)->assertNotFound();
        }
        try {
            DB::table('member_entries')->insert(['club_id' => $this->club->id, 'event_id' => $event->id, 'membership_id' => $other->owner_membership_id, 'amount_minor' => 1]);
            $this->fail('Foreign FK accepted');
        } catch (QueryException $e) {
            $this->assertSame(1452, $e->errorInfo[1]);
        }
    }
}
