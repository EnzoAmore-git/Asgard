<?php

namespace Tests\Feature\Finance;

use App\Models\Club;
use App\Models\Membership;
use App\Models\User;
use App\Services\Clubs\ClubService;
use App\Services\Finance\FinancialRead;
use App\Services\Finance\OpeningBalances;
use App\Services\Finance\PaymentDemo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentsTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private User $owner;

    private User $fighter;

    private User $receiver;

    private Membership $member;

    private Membership $holder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(15, 0));
        // Uses the approved three-day warning window.
        config(['finance.duplicate_window_days' => 3]);
        $this->owner = User::factory()->create();
        $operator = User::factory()->create(['is_operator' => true]);
        $service = app(ClubService::class);
        $this->club = $service->create($operator, $this->owner, ['name' => 'Платежи', 'owner_name' => 'Казначей', 'timezone' => 'Europe/Moscow', 'currency' => 'RUB', 'period_start_day' => 10]);
        $service->setPermissions($this->owner, $this->club, $this->club->owner_membership_id, ['finance.manage']);
        $this->fighter = User::factory()->create();
        $this->receiver = User::factory()->create();
        $token = basename($service->invite($this->owner, $this->club)['url']);
        $service->join($this->fighter, $token, 'Боец');
        $service->join($this->receiver, $token, 'Получатель');
        $this->member = Membership::where('user_id', $this->fighter->id)->firstOrFail();
        $this->holder = Membership::where('user_id', $this->receiver->id)->firstOrFail();
        $service->setPermissions($this->owner, $this->club, $this->holder->id, ['cash.receive']);
        $this->opening('card', '3000000');
        $this->opening('member', '-1500000', $this->member->id);
    }

    private function opening(string $target, string $amount, ?int $member = null): void
    {
        app(OpeningBalances::class)->set($this->club, $this->owner, (string) Str::uuid(), ['target' => $target, 'amount_minor' => $amount, 'membership_id' => $member, 'previous_event_id' => null, 'currency' => 'RUB', 'date' => '2026-10-24', 'reason' => 'Начальные данные']);
    }

    private function data(string $amount = '1500000'): array
    {
        return ['amount_minor' => $amount, 'currency' => 'RUB', 'date' => '2026-10-24', 'reason' => 'Подтверждённый факт', 'method' => 'card', 'membership_id' => $this->member->id, 'duplicates_acknowledged' => false];
    }

    private function postAs(User $user, string $path, array $data, ?string $key = null)
    {
        return $this->actingAs($user)->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->postJson('/api/clubs/'.$this->club->id.'/finance/'.$path, $data);
    }

    private function totals(): array
    {
        return app(FinancialRead::class)->summary($this->club, $this->owner);
    }

    private function balance(): string
    {
        return app(FinancialRead::class)->own($this->club, $this->fighter)['balance_minor'];
    }

    public function test_claim_has_no_effect_and_single_confirmation_pays_six_month_debt(): void
    {
        $id = $this->postAs($this->fighter, 'claims', $this->data())->assertOk()->json('id');
        $this->assertSame('-1500000', $this->balance());
        $this->assertSame('3000000', $this->totals()['total_minor']);
        $d = ['decision' => 'confirm', 'reason' => 'Проверено по банку', 'duplicates_acknowledged' => false];
        $key = (string) Str::uuid();
        $first = $this->postAs($this->owner, "claims/$id/review", $d, $key)->assertOk()->json();
        $this->postAs($this->owner, "claims/$id/review", $d, $key)->assertOk()->assertJson($first);
        $this->postAs($this->owner, "claims/$id/review", $d)->assertOk();
        $this->assertSame('0', $this->balance());
        $this->assertSame('4500000', $this->totals()['card_minor']);
        $this->assertDatabaseCount('payment_receipts', 1);
    }

    public function test_rejection_keeps_balances_and_author_reason(): void
    {
        $id = $this->postAs($this->fighter, 'claims', $this->data())->json('id');
        $this->postAs($this->owner, "claims/$id/review", ['decision' => 'reject', 'reason' => 'Не найдено поступление', 'duplicates_acknowledged' => false])->assertOk();
        $this->assertSame('-1500000', $this->balance());
        $this->assertDatabaseHas('payment_claims', ['id' => $id, 'status' => 'rejected', 'reviewed_by' => $this->owner->id]);
    }

    public function test_receiver_alone_confirms_cash_and_partial_transfer_is_not_income(): void
    {
        $claim = $this->postAs($this->fighter, 'claims', $this->data('500000') + ['custodian_membership_id' => $this->holder->id]);
        // Correct method must explicitly be cash; array union above intentionally retains card and is invalid.
        $claim->assertStatus(422);
        $d = array_replace($this->data('500000'), ['method' => 'cash', 'custodian_membership_id' => $this->holder->id]);
        $id = $this->postAs($this->fighter, 'claims', $d)->assertOk()->json('id');
        $review = ['decision' => 'confirm', 'reason' => 'Принял наличные', 'duplicates_acknowledged' => false];
        $this->postAs($this->owner, "claims/$id/review", $review)->assertForbidden();
        $this->postAs($this->receiver, "claims/$id/review", $review)->assertOk();
        $t = $this->totals();
        $this->assertSame('3500000', $t['total_minor']);
        $this->assertSame('3000000', $t['card_minor']);
        $this->assertSame('500000', $t['custody_minor']);
        $this->assertSame('-1000000', $this->balance());
        $transfer = $this->data('300000') + ['custodian_membership_id' => $this->holder->id];
        $this->postAs($this->receiver, 'transfers', $transfer)->assertForbidden();
        $this->postAs($this->owner, 'transfers', $transfer)->assertOk();
        $t = $this->totals();
        $this->assertSame('3500000', $t['total_minor']);
        $this->assertSame('3300000', $t['card_minor']);
        $this->assertSame('200000', $t['custody_minor']);
        $this->assertSame('-1000000', $this->balance());
        $this->postAs($this->owner, 'transfers', array_replace($transfer, ['amount_minor' => '200001']))->assertStatus(422);
    }

    public function test_revoked_receiver_cannot_accept_but_old_balance_is_retained(): void
    {
        $d = array_replace($this->data('500000'), ['method' => 'cash']);
        $this->postAs($this->receiver, 'receipts', $d)->assertOk();
        app(ClubService::class)->setPermissions($this->owner, $this->club, $this->holder->id, []);
        $this->postAs($this->receiver, 'receipts', $d)->assertForbidden();
        $this->assertSame('500000', $this->totals()['custody_minor']);
        $this->postAs($this->owner, 'transfers', $this->data('500000') + ['custodian_membership_id' => $this->holder->id])->assertOk();
    }

    public function test_unknown_receipt_then_identification_and_wrong_payer_correction_never_add_money_twice(): void
    {
        $id = $this->postAs($this->owner, 'receipts', array_replace($this->data('250000'), ['membership_id' => null]))->assertOk()->json('receipt_id');
        $this->assertSame('-1500000', $this->balance());
        $this->assertSame('3250000', $this->totals()['card_minor']);
        $this->postAs($this->owner, "receipts/$id/identify", ['membership_id' => $this->holder->id, 'version' => 1, 'reason' => 'Установлен отправитель'])->assertOk();
        $this->assertSame('-1500000', $this->balance());
        $this->postAs($this->owner, "receipts/$id/identify", ['membership_id' => $this->member->id, 'version' => 2, 'reason' => 'Исправлен отправитель'])->assertOk();
        $this->assertSame('-1250000', $this->balance());
        $this->assertSame('3250000', $this->totals()['card_minor']);
        $this->postAs($this->owner, "receipts/$id/identify", ['membership_id' => $this->holder->id, 'version' => 2, 'reason' => 'Устаревшая форма'])->assertConflict();
    }

    public function test_late_claim_links_existing_manual_payment_without_second_receipt(): void
    {
        $receipt = $this->postAs($this->owner, 'receipts', $this->data())->json('receipt_id');
        $claim = $this->postAs($this->fighter, 'claims', $this->data())->json('id');
        $this->postAs($this->owner, "claims/$claim/review", ['decision' => 'link', 'receipt_id' => $receipt, 'reason' => 'Это ранее внесённая оплата', 'duplicates_acknowledged' => false])->assertOk();
        $this->assertSame('0', $this->balance());
        $this->assertDatabaseCount('payment_receipts', 1);
        $second = $this->postAs($this->fighter, 'claims', $this->data())->json('id');
        $this->postAs($this->owner, "claims/$second/review", ['decision' => 'link', 'receipt_id' => $receipt, 'reason' => 'Пробуем связать ещё раз', 'duplicates_acknowledged' => false])->assertConflict();
    }

    public function test_duplicate_warning_is_not_a_ban_on_two_real_equal_payments(): void
    {
        $this->postAs($this->owner, 'receipts', $this->data())->assertOk();
        $this->postAs($this->owner, 'receipts', $this->data())->assertConflict();
        $this->postAs($this->owner, 'receipts', array_replace($this->data(), ['duplicates_acknowledged' => true]))->assertOk();
        $this->assertSame('1500000', $this->balance());
    }

    public function test_refund_checks_live_overpayment_and_retains_original_receipt(): void
    {
        $id = $this->postAs($this->owner, 'receipts', $this->data('1600000'))->json('receipt_id');
        $d = $this->data('100001') + ['version' => 1];
        $this->postAs($this->owner, "receipts/$id/refund", $d)->assertStatus(422);
        $this->postAs($this->owner, "receipts/$id/refund", array_replace($d, ['amount_minor' => '100000']))->assertOk();
        $this->assertSame('0', $this->balance());
        $this->assertSame('4500000', $this->totals()['card_minor']);
        $this->assertDatabaseHas('payment_receipts', ['id' => $id, 'amount_minor' => 1600000, 'refunded_minor' => 100000]);
        $this->postAs($this->owner, "receipts/$id/refund", array_replace($d, ['amount_minor' => '1', 'version' => 2]))->assertStatus(422);
    }

    public function test_template_creates_expected_cost_only_and_payment_is_once(): void
    {
        $id = $this->postAs($this->owner, 'expense-templates', ['title' => 'Аренда зала', 'amount_minor' => '1500000', 'currency' => 'RUB', 'day_of_month' => 31, 'active' => true, 'version' => 1])->assertOk()->json('id');
        $occ = $this->postAs($this->owner, "expense-templates/$id/generate", ['month' => '2026-02'])->assertOk()->json('id');
        $this->postAs($this->owner, "expense-templates/$id/generate", ['month' => '2026-02'])->assertOk()->assertJson(['id' => $occ]);
        $this->assertSame('3000000', $this->totals()['card_minor']);
        $this->assertDatabaseHas('expense_occurrences', ['id' => $occ, 'due_on' => '2026-02-28', 'event_id' => null]);
        $this->postAs($this->owner, "expenses/$occ/pay", $this->data())->assertOk();
        $this->postAs($this->owner, "expenses/$occ/pay", $this->data())->assertOk();
        $this->assertSame('1500000', $this->totals()['card_minor']);
        $this->assertSame('-1500000', $this->balance());
    }

    public function test_reconciliation_keeps_snapshot_and_recalculates_after_missing_expense(): void
    {
        $id = $this->postAs($this->owner, 'reconciliations', ['actual_minor' => '1500000', 'as_of' => '2026-10-24T17:00', 'comment' => 'Сверил по банку'])->assertOk()->assertJson(['calculated_minor' => '3000000', 'difference_minor' => '-1500000'])->json('id');
        $this->assertSame('3000000', $this->totals()['card_minor']);
        $this->postAs($this->owner, 'expenses', $this->data())->assertOk();
        $r = $this->actingAs($this->owner)->getJson('/api/clubs/'.$this->club->id.'/finance/reconciliations')->assertOk()->json('data.0');
        $this->assertSame('3000000', $r['calculated_minor']);
        $this->assertSame('0', $r['current_difference_minor']);
        $this->postAs($this->owner, "reconciliations/$id/adjust", ['expected_calculated_minor' => '1500000', 'currency' => 'RUB', 'reason' => 'Причина неизвестна'])->assertStatus(422);
    }

    public function test_unexplained_adjustment_requires_reason_and_rejects_stale_calculation(): void
    {
        $id = $this->postAs($this->owner, 'reconciliations', ['actual_minor' => '2950000', 'as_of' => '2026-10-24T17:00', 'comment' => 'В банке меньше'])->json('id');
        $this->postAs($this->owner, "reconciliations/$id/adjust", ['expected_calculated_minor' => '3000000', 'currency' => 'RUB', 'reason' => 'Не удалось найти причину'])->assertOk();
        $this->assertSame('2950000', $this->totals()['card_minor']);
        $this->postAs($this->owner, "reconciliations/$id/adjust", ['expected_calculated_minor' => '2950000', 'currency' => 'RUB', 'reason' => 'Повторное исправление'])->assertConflict();
        $this->assertDatabaseHas('card_reconciliations', ['id' => $id, 'calculated_minor' => 3000000, 'difference_minor' => -50000]);
    }

    public function test_cash_receiver_cannot_read_other_finances_or_claims_and_member_cannot_read_inbox(): void
    {
        $this->postAs($this->fighter, 'claims', $this->data())->assertOk();
        $url = '/api/clubs/'.$this->club->id.'/finance/';
        $this->actingAs($this->receiver)->getJson($url.'claims?scope=inbox')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->receiver)->getJson($url.'summary')->assertForbidden();
        $this->actingAs($this->fighter)->getJson($url.'claims?scope=inbox')->assertForbidden();
        $this->actingAs($this->fighter)->getJson($url.'receipts')->assertForbidden();
        $this->postAs($this->fighter, 'receipts', $this->data())->assertForbidden();
    }

    public function test_cross_club_membership_and_floating_amount_are_rejected(): void
    {
        $other = app(ClubService::class)->create(User::factory()->create(['is_operator' => true]), User::factory()->create(), ['name' => 'Другой', 'owner_name' => 'Другой', 'timezone' => 'UTC', 'currency' => 'RUB', 'period_start_day' => 10]);
        $this->postAs($this->owner, 'receipts', array_replace($this->data(), ['membership_id' => $other->owner_membership_id]))->assertNotFound();
        $this->postAs($this->owner, 'receipts', array_replace($this->data(), ['amount_minor' => 2.5]))->assertStatus(422);
    }

    public function test_failed_post_rolls_back_claim_state_journal_key_and_outbox(): void
    {
        $id = $this->postAs($this->fighter, 'claims', $this->data())->json('id');
        $before = DB::table('financial_events')->count();
        DB::listen(function ($q) {
            if (str_starts_with($q->sql, 'insert into `financial_outbox`')) {
                throw new \RuntimeException('Injected failure');
            }
        });
        $this->postAs($this->owner, "claims/$id/review", ['decision' => 'confirm', 'reason' => 'Проверено в банке', 'duplicates_acknowledged' => false])->assertStatus(500);
        $this->assertDatabaseHas('payment_claims', ['id' => $id, 'status' => 'pending', 'receipt_id' => null]);
        $this->assertDatabaseCount('payment_receipts', 0);
        $this->assertSame($before, DB::table('financial_events')->count());
    }

    public function test_own_claims_are_scoped_and_cash_inbox_does_not_expose_comment(): void
    {
        $this->postAs($this->fighter, 'claims', $this->data())->assertOk();
        $this->postAs($this->owner, 'claims', $this->data('12300'))->assertOk();
        $url = '/api/clubs/'.$this->club->id.'/finance/claims';
        $this->actingAs($this->fighter)->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.club_name', 'Боец');
        $this->postAs($this->fighter, 'claims', array_replace($this->data('500000'), ['method' => 'cash', 'custodian_membership_id' => $this->holder->id, 'reason' => 'Приватный комментарий участника']));
        $r = $this->actingAs($this->receiver)->getJson($url.'?scope=inbox')->assertOk()->assertJsonCount(1, 'data');
        $this->assertArrayNotHasKey('comment', $r->json('data.0'));
        $this->assertArrayNotHasKey('review_reason', $r->json('data.0'));
    }

    public function test_manual_request_retry_preserves_one_receipt_and_changed_payload_conflicts(): void
    {
        $key = (string) Str::uuid();
        $r = $this->postAs($this->owner, 'receipts', $this->data(), $key)->assertOk()->json();
        $this->postAs($this->owner, 'receipts', $this->data(), $key)->assertOk()->assertJson($r);
        $this->postAs($this->owner, 'receipts', array_replace($this->data(), ['amount_minor' => '100']), $key)->assertConflict();
        $this->assertDatabaseCount('payment_receipts', 1);
    }

    public function test_template_edit_does_not_duplicate_or_rewrite_existing_month(): void
    {
        $d = ['title' => 'Аренда', 'amount_minor' => '1500000', 'currency' => 'RUB', 'day_of_month' => 10, 'active' => true, 'version' => 1];
        $id = $this->postAs($this->owner, 'expense-templates', $d)->json('id');
        $occ = $this->postAs($this->owner, "expense-templates/$id/generate", ['month' => '2026-10'])->json('id');
        $this->actingAs($this->owner)->withHeader('Idempotency-Key', (string) Str::uuid())->putJson('/api/clubs/'.$this->club->id.'/finance/expense-templates/'.$id, array_replace($d, ['day_of_month' => 20, 'amount_minor' => '1600000']))->assertOk();
        $this->postAs($this->owner, "expense-templates/$id/generate", ['month' => '2026-10'])->assertOk()->assertJson(['id' => $occ]);
        $this->assertDatabaseCount('expense_occurrences', 1);
        $this->assertDatabaseHas('expense_occurrences', ['id' => $occ, 'due_on' => '2026-10-10', 'amount_minor' => 1500000]);
    }

    public function test_reconciliation_rejects_stale_adjustment_after_backdated_fact(): void
    {
        $id = $this->postAs($this->owner, 'reconciliations', ['actual_minor' => '2900000', 'as_of' => '2026-10-24T17:00', 'comment' => 'Проверка снимка'])->json('id');
        $this->postAs($this->owner, 'expenses', $this->data('50000'))->assertOk();
        $this->postAs($this->owner, "reconciliations/$id/adjust", ['expected_calculated_minor' => '3000000', 'currency' => 'RUB', 'reason' => 'Неизвестная причина'])->assertConflict();
        $this->assertSame('2950000', $this->totals()['card_minor']);
    }

    public function test_local_payment_demo_is_repeatable_without_resetting_changes(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $one = app(PaymentDemo::class)->create();
        $count = DB::table('financial_events')->count();
        $two = app(PaymentDemo::class)->create();
        $this->assertSame($one, $two);
        $this->assertSame($count, DB::table('financial_events')->count());
    }

    public function test_cash_receiver_duplicate_check_does_not_reveal_other_payments(): void
    {

        $this->postAs($this->owner, 'receipts', $this->data('500000'))->assertOk();
        $this->postAs($this->receiver, 'receipts', array_replace($this->data('500000'), ['method' => 'cash']))->assertOk();
        $this->assertSame('-500000', $this->balance());
        $this->assertSame('4000000', $this->totals()['total_minor']);
    }

    public function test_returning_starting_advance_does_not_invent_payment_or_exceed_current_advance(): void
    {
        $this->opening('member', '100000', $this->holder->id);
        $opening = DB::table('financial_events')->where('type', 'opening_member')->where('opening_membership_id', $this->holder->id)->first();
        $d = $this->data('60000') + ['opening_event_id' => $opening->id];
        $d['membership_id'] = $this->holder->id;
        $key = (string) Str::uuid();
        $this->postAs($this->owner, 'opening-refunds', $d, $key)->assertOk();
        $this->postAs($this->owner, 'opening-refunds', $d, $key)->assertOk();
        $this->postAs($this->owner, 'opening-refunds', $d)->assertStatus(422);
        $this->assertSame('2940000', $this->totals()['card_minor']);
        $this->assertDatabaseCount('payment_receipts', 0);
        $this->assertSame('40000', app(FinancialRead::class)->own($this->club, $this->receiver)['balance_minor']);
    }

    public function test_partial_bank_cancellation_can_restore_debt_and_only_remaining_payer_credit_moves(): void
    {
        $id = $this->postAs($this->owner, 'receipts', $this->data('1500000'))->json('receipt_id');
        $this->postAs($this->owner, "receipts/$id/bank-cancel", $this->data('100000') + ['version' => 1])->assertOk();
        $this->assertSame('-100000', $this->balance());
        $this->assertSame('4400000', $this->totals()['card_minor']);
        $this->postAs($this->owner, "receipts/$id/identify", ['membership_id' => $this->holder->id, 'version' => 2, 'reason' => 'Исправляем плательщика'])->assertOk();
        $this->assertSame('-1500000', $this->balance());
        $this->assertSame('1400000', app(FinancialRead::class)->own($this->club, $this->receiver)['balance_minor']);
        $this->assertSame('4400000', $this->totals()['card_minor']);
        $this->postAs($this->owner, "receipts/$id/bank-cancel", $this->data('1400001') + ['version' => 3])->assertStatus(422);
        $this->postAs($this->owner, "receipts/$id/bank-cancel", $this->data('1400000') + ['version' => 3])->assertOk();
        $this->assertSame('3000000', $this->totals()['card_minor']);
        $this->assertSame('0', app(FinancialRead::class)->own($this->club, $this->receiver)['balance_minor']);
        $this->postAs($this->owner, "receipts/$id/bank-cancel", $this->data('1') + ['version' => 4])->assertStatus(422);
    }

    public function test_bank_cancellation_unknown_payer_and_already_returned_part(): void
    {
        $id = $this->postAs($this->owner, 'receipts', array_replace($this->data('250000'), ['membership_id' => null]))->json('receipt_id');
        $this->postAs($this->owner, "receipts/$id/bank-cancel", $this->data('50000') + ['version' => 1])->assertOk();
        $this->assertSame('-1500000', $this->balance());
        $this->postAs($this->owner, "receipts/$id/identify", ['membership_id' => $this->holder->id, 'version' => 2, 'reason' => 'Установили отправителя'])->assertOk();
        $this->postAs($this->owner, "receipts/$id/refund", $this->data('100000') + ['version' => 3])->assertOk();
        $this->postAs($this->owner, "receipts/$id/bank-cancel", $this->data('100001') + ['version' => 4])->assertStatus(422);
        $this->postAs($this->owner, "receipts/$id/bank-cancel", $this->data('100000') + ['version' => 4])->assertOk();
        $this->assertSame('3000000', $this->totals()['card_minor']);
    }

    public function test_three_day_warning_boundary_and_equal_real_payments(): void
    {
        $this->postAs($this->owner, 'receipts', array_replace($this->data('250000'), ['date' => '2026-10-20']))->assertOk();
        $this->postAs($this->owner, 'receipts', array_replace($this->data('250000'), ['date' => '2026-10-23']))->assertConflict();
        $this->postAs($this->owner, 'receipts', array_replace($this->data('250000'), ['date' => '2026-10-24']))->assertOk();
    }

    public function test_private_cheque_is_downloadable_only_by_sender_and_finance(): void
    {
        Storage::fake('receipts');
        $id = $this->postAs($this->fighter, 'claims', $this->data())->json('id');
        $file = UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
        $path = '/api/clubs/'.$this->club->id.'/finance/claims/'.$id.'/cheque';
        $key = (string) Str::uuid();
        $this->actingAs($this->fighter)->withHeader('Idempotency-Key', $key)->post($path, ['file' => $file], ['Accept' => 'application/json'])->assertOk();
        $this->actingAs($this->fighter)->withHeader('Idempotency-Key', $key)->post($path, ['file' => $file], ['Accept' => 'application/json'])->assertOk();
        $this->assertDatabaseCount('payment_attachments', 1);
        $this->actingAs($this->fighter)->get($path)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->owner)->get($path)->assertOk();
        $this->actingAs($this->receiver)->getJson($path)->assertForbidden();
        app(ClubService::class)->setPermissions($this->owner, $this->club, $this->club->owner_membership_id, []);
        $this->actingAs($this->owner)->getJson($path)->assertForbidden();
        $this->assertSame('-1500000', $this->balance());
        $this->assertCount(1, Storage::disk('receipts')->allFiles());
    }

    public function test_cheque_validates_content_size_single_file_and_cross_club_scope(): void
    {
        Storage::fake('receipts');
        $id = $this->postAs($this->fighter, 'claims', $this->data())->json('id');
        $path = '/api/clubs/'.$this->club->id.'/finance/claims/'.$id.'/cheque';
        $upload = fn (User $user, $file) => $this->actingAs($user)->withHeader('Idempotency-Key', (string) Str::uuid())->post($path, ['file' => $file], ['Accept' => 'application/json']);
        $bad = UploadedFile::fake()->createWithContent('bad.jpg', '<html>bad</html>');
        $upload($this->fighter, new UploadedFile($bad->getRealPath(), 'bad.jpg', 'image/jpeg', null, true))->assertStatus(422);
        $upload($this->fighter, UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf'))->assertStatus(422);
        $file = UploadedFile::fake()->createWithContent('ok.pdf', "%PDF-1.4\n%%EOF");
        $upload($this->receiver, $file)->assertForbidden();
        $upload($this->fighter, $file)->assertOk();
        $upload($this->fighter, UploadedFile::fake()->createWithContent('other.pdf', "%PDF-1.4\n%other\n%%EOF"))->assertConflict();
        $this->assertDatabaseCount('payment_attachments', 1);
        $other = app(ClubService::class)->create(User::factory()->create(['is_operator' => true]), $this->owner, ['name' => 'Другой клуб', 'owner_name' => 'Другой', 'timezone' => 'UTC', 'currency' => 'RUB', 'period_start_day' => 10]);
        $this->actingAs($this->owner)->getJson('/api/clubs/'.$other->id.'/finance/claims/'.$id.'/cheque')->assertNotFound();
    }

    public function test_failed_attachment_transaction_removes_only_new_file(): void
    {
        Storage::fake('receipts');
        $id = $this->postAs($this->fighter, 'claims', $this->data())->json('id');
        DB::listen(function ($q) {
            if (str_starts_with($q->sql, 'insert into `payment_attachments`')) {
                throw new \RuntimeException('Injected file transaction failure');
            }
        });
        $this->actingAs($this->fighter)->withHeader('Idempotency-Key', (string) Str::uuid())->post('/api/clubs/'.$this->club->id.'/finance/claims/'.$id.'/cheque', ['file' => UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n%%EOF")], ['Accept' => 'application/json'])->assertStatus(500);
        $this->assertDatabaseCount('payment_attachments', 0);
        $this->assertSame([], Storage::disk('receipts')->allFiles());
    }

    public function test_late_claim_can_link_original_payment_after_partial_cancellation_without_new_money(): void
    {
        $id = $this->postAs($this->owner, 'receipts', $this->data())->json('receipt_id');
        $this->postAs($this->owner, "receipts/$id/bank-cancel", $this->data('100000') + ['version' => 1])->assertOk();
        $claim = $this->postAs($this->fighter, 'claims', $this->data())->json('id');
        $this->postAs($this->owner, "claims/$claim/review", ['decision' => 'link', 'receipt_id' => $id, 'reason' => 'Связываем исходный платёж, часть банк отменил', 'duplicates_acknowledged' => false])->assertOk();
        $this->assertSame('-100000', $this->balance());
        $this->assertSame('4400000', $this->totals()['card_minor']);
        $this->assertDatabaseCount('payment_receipts', 1);
    }

    public function test_png_content_and_exact_ten_megabyte_pdf_are_accepted_but_wrong_extension_is_not(): void
    {
        Storage::fake('receipts');
        $one = $this->postAs($this->fighter, 'claims', $this->data('12300'))->json('id');
        $png = UploadedFile::fake()->createWithContent('tiny.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII='));
        $this->actingAs($this->fighter)->withHeader('Idempotency-Key', (string) Str::uuid())->post('/api/clubs/'.$this->club->id.'/finance/claims/'.$one.'/cheque', ['file' => new UploadedFile($png->getRealPath(), 'tiny.png', 'image/png', null, true)], ['Accept' => 'application/json'])->assertOk();
        $two = $this->postAs($this->fighter, 'claims', $this->data('45600'))->json('id');
        $path = '/api/clubs/'.$this->club->id.'/finance/claims/'.$two.'/cheque';
        $pdf = UploadedFile::fake()->createWithContent('limit.pdf', "%PDF-1.4\n".str_repeat(' ', 10 * 1024 * 1024 - 9));
        $this->actingAs($this->fighter)->withHeader('Idempotency-Key', (string) Str::uuid())->post($path, ['file' => new UploadedFile($pdf->getRealPath(), 'limit.html', 'text/html', null, true)], ['Accept' => 'application/json'])->assertStatus(422);
        $this->actingAs($this->fighter)->withHeader('Idempotency-Key', (string) Str::uuid())->post($path, ['file' => new UploadedFile($pdf->getRealPath(), 'limit.pdf', 'application/pdf', null, true)], ['Accept' => 'application/json'])->assertOk();
        $this->assertDatabaseHas('payment_attachments',['claim_id' => $two, 'size' => 10 * 1024 * 1024]);
    }
}
