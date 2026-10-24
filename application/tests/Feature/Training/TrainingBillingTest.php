<?php

namespace Tests\Feature\Training;

use App\Models\Club;
use App\Models\Membership;
use App\Models\User;
use App\Services\Clubs\ClubAccess;
use App\Services\Clubs\ClubService;
use App\Services\Finance\FinancialRead;
use App\Services\Finance\Journal;
use App\Services\Finance\OpeningBalances;
use App\Services\Finance\Posting;
use App\Services\Training\Billing;
use App\Services\Training\Calendar;
use App\Services\Training\TrainingDemo;
use App\Services\Training\TrainingRead;
use App\Services\Training\Trainings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TrainingBillingTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private User $owner;

    private User $operator;

    private Membership $member;

    private Membership $fighter;

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function clock(string $time): void
    {
        Carbon::setTestNow($time);
        CarbonImmutable::setTestNow($time);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-10-24T12:00:00Z');
        $this->operator = User::factory()->create(['is_operator' => true]);
        $this->owner = User::factory()->create();
        $this->club = app(ClubService::class)->create($this->operator, $this->owner, ['name' => 'Тренировки', 'owner_name' => 'Рагнар', 'timezone' => 'Europe/Moscow', 'currency' => 'RUB', 'period_start_day' => 10]);
        $this->member = Membership::findOrFail($this->club->owner_membership_id);
        app(ClubService::class)->setPermissions($this->owner, $this->club, $this->member->id, ['schedule.manage', 'finance.manage', 'members.manage']);
        $this->fighter = $this->join('Бьорн');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function join(string $name): Membership
    {
        return app(ClubService::class)->join(User::factory()->create(), basename(app(ClubService::class)->invite($this->owner, $this->club)['url']), $name);
    }

    private function activate(): void
    {
        app(Billing::class)->configure($this->club, $this->owner, $this->key(), ['effective_from' => '2026-11-10', 'amount_minor' => '250000', 'reason' => 'Первый тариф']);
    }

    private function training(string $time = '2026-11-11T18:00', ?int $responsible = null): array
    {
        return app(Trainings::class)->create($this->club, $this->owner, $this->key(), ['title' => 'Тренировка', 'local_start' => $time, 'responsible_membership_id' => $responsible ?? $this->member->id]);
    }

    private function draft(array $t, array $present, ?User $actor = null): array
    {
        return app(Trainings::class)->draft($this->club, $actor ?? $this->owner, $this->key(), $t['id'], ['version' => $t['version'], 'present_membership_ids' => $present]);
    }

    private function close(array $t, ?User $actor = null): array
    {
        return app(Trainings::class)->close($this->club, $actor ?? $this->owner, $this->key(), $t['id'], ['version' => $t['version']]);
    }

    private function balance(?Membership $m = null): string
    {
        $m ??= $this->fighter;

        return app(FinancialRead::class)->own($this->club, User::findOrFail($m->user_id))['balance_minor'];
    }

    private function failsWith(int $status, \Closure $work): void
    {
        try {
            $work();
            $this->fail('Expected failure');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        } catch (ModelNotFoundException) {
            $this->assertSame(404, $status);
        }
    }

    public function test_short_months_leap_year_and_timezone_preserve_nominal_day(): void
    {
        $this->club->period_start_day = 31;
        $calendar = app(Calendar::class);
        foreach ([['2027-02-27T22:00:00Z', '2027-02-28', '2027-03-31'], ['2027-02-27T20:59:00Z', '2027-01-31', '2027-02-28'], ['2027-02-28T00:00:00Z', '2027-02-28', '2027-03-31'], ['2028-02-29T00:00:00Z', '2028-02-29', '2028-03-31'], ['2027-03-31T00:00:00Z', '2027-03-31', '2027-04-30']] as [$instant,$start,$end]) {
            $p = $calendar->period($this->club, CarbonImmutable::parse($instant));
            $this->assertSame($start, $p['starts_on']);
            $this->assertSame($end, $p['ends_on']);
        }
        $this->club->period_start_day = 10;
        $p = $calendar->period($this->club, CarbonImmutable::parse('2026-11-09T21:00:00Z'));
        $this->assertSame('2026-11-10', $p['starts_on']);
    }

    public function test_rsvp_and_draft_have_no_financial_effect(): void
    {
        $this->activate();
        $t = $this->training();
        $actor = User::findOrFail($this->fighter->user_id);
        app(Trainings::class)->rsvp($this->club, $actor, $this->key(), $t['id'], ['response' => 'yes']);
        $t = $this->draft($t, [$this->fighter->id]);
        $this->assertSame('0', $this->balance());
        $this->assertDatabaseCount('financial_events', 0);
        $period = app(Calendar::class)->ensure($this->club, CarbonImmutable::parse('2026-11-11T15:00:00Z'));
        $training = app(Trainings::class)->find($this->club, $t['id']);
        $this->failsWith(409, fn () => app(Billing::class)->attend($this->club, $this->owner, $training, $period, $this->fighter->id, fn () => null));
        $this->assertSame(0, app(TrainingRead::class)->show($this->club, $this->owner, $t['id'])['confirmed_count']);
    }

    public function test_first_confirmed_attendance_charges_once_across_five_trainings_and_preserves_cash(): void
    {
        $this->activate();
        app(OpeningBalances::class)->set($this->club, $this->owner, $this->key(), ['target' => 'card', 'membership_id' => null, 'amount_minor' => '3000000', 'currency' => 'RUB', 'date' => '2026-10-24', 'reason' => 'Начальная карта', 'previous_event_id' => null]);
        $this->clock('2026-11-20T20:00:00Z');
        for ($day = 11; $day <= 15; $day++) {
            $t = $this->draft($this->training('2026-11-'.$day.'T18:00'), [$this->fighter->id]);
            $this->close($t);
        }
        $this->assertSame('-250000', $this->balance());
        $this->assertDatabaseCount('subscription_obligations', 1);
        $this->assertSame(1, DB::table('financial_events')->where('type', 'charge')->count());
        $this->assertSame('3000000', app(FinancialRead::class)->summary($this->club, $this->owner)['total_minor']);
        $this->assertSame(5, DB::table('training_attendances')->where('confirmed_present', true)->count());
    }

    public function test_going_but_absent_has_no_charge_and_unregistered_active_member_can_attend(): void
    {
        $this->activate();
        $thor = $this->join('Тор');
        $t = $this->training();
        app(Trainings::class)->rsvp($this->club, User::findOrFail($this->fighter->user_id), $this->key(), $t['id'], ['response' => 'yes']);
        $t = $this->draft($t, [$thor->id]);
        $this->clock('2026-11-11T20:00:00Z');
        $this->close($t);
        $this->assertSame('0', $this->balance());
        $this->assertSame('-250000', $this->balance($thor));
    }

    public function test_exempt_attendance_counts_without_charge(): void
    {
        $this->activate();
        app(Billing::class)->exemption($this->club, $this->owner, $this->key(), $this->fighter->id, ['effective_from' => '2026-11-10', 'exempt' => true, 'reason' => 'Первоначальное освобождение']);
        $this->clock('2026-11-20T20:00:00Z');
        for ($day = 11; $day <= 15; $day++) {
            $this->close($this->draft($this->training('2026-11-'.$day.'T18:00'), [$this->fighter->id]));
        }
        $this->assertSame('0', $this->balance());
        $this->assertDatabaseCount('financial_events', 0);
        $this->assertDatabaseCount('subscription_obligations', 1);
        $this->assertSame(5, DB::table('training_attendances')->where('confirmed_present', true)->count());
    }

    public function test_attendance_before_activation_does_not_charge_when_closed_late(): void
    {
        $this->activate();
        $t = $this->draft($this->training('2026-11-09T18:00'), [$this->fighter->id]);
        $this->clock('2026-12-20T20:00:00Z');
        $this->close($t);
        $this->assertSame('0', $this->balance());
        $this->assertDatabaseCount('subscription_obligations', 0);
        $this->assertDatabaseHas('billing_periods', ['starts_on' => '2026-10-10', 'ends_on' => '2026-11-10']);
    }

    public function test_late_closing_uses_historical_period_tariff_and_not_current_exemption(): void
    {
        $this->activate();
        $t = $this->draft($this->training('2026-11-11T18:00'), [$this->fighter->id]);
        $this->clock('2026-11-15T12:00:00Z');
        app(Billing::class)->configure($this->club, $this->owner, $this->key(), ['effective_from' => '2026-12-10', 'amount_minor' => '300000', 'reason' => 'Будущий тариф']);
        app(Billing::class)->exemption($this->club, $this->owner, $this->key(), $this->fighter->id, ['effective_from' => '2026-12-10', 'exempt' => true, 'reason' => 'Будущее освобождение']);
        $this->clock('2026-12-15T20:00:00Z');
        $this->close($t);
        $this->assertSame('-250000', $this->balance());
        $this->assertDatabaseHas('subscription_obligations', ['tariff_minor' => 250000, 'exempt' => false, 'current_amount_minor' => 250000]);
        $this->close($this->draft($this->training('2026-12-11T18:00'), [$this->fighter->id]));
        $this->assertSame('-250000', $this->balance());
        $this->assertDatabaseHas('subscription_obligations', ['tariff_minor' => 300000, 'exempt' => true, 'current_amount_minor' => 0]);
    }

    public function test_future_only_settings_keep_launch_date_and_allow_resuming_payment_later(): void
    {
        $this->activate();
        $this->clock('2026-11-15T12:00:00Z');
        $this->failsWith(422, fn () => app(Billing::class)->configure($this->club, $this->owner, $this->key(), ['effective_from' => '2026-11-10', 'amount_minor' => '1', 'reason' => 'Недопустимое прошлое']));
        $this->failsWith(422, fn () => app(Billing::class)->configure($this->club, $this->owner, $this->key(), ['effective_from' => '2026-12-11', 'amount_minor' => '1', 'reason' => 'Не граница периода']));
        app(Billing::class)->configure($this->club, $this->owner, $this->key(), ['effective_from' => '2026-12-10', 'amount_minor' => '300000', 'reason' => 'Новый тариф']);
        $this->assertDatabaseHas('billing_settings', ['active_from' => '2026-11-10']);
        app(Billing::class)->exemption($this->club, $this->owner, $this->key(), $this->fighter->id, ['effective_from' => '2026-12-10', 'exempt' => true, 'reason' => 'Освобождение']);
        $this->clock('2026-12-15T12:00:00Z');
        app(Billing::class)->exemption($this->club, $this->owner, $this->key(), $this->fighter->id, ['effective_from' => '2027-01-10', 'exempt' => false, 'reason' => 'Снова платит']);
        $this->clock('2027-01-15T20:00:00Z');
        $this->close($this->draft($this->training('2027-01-11T18:00'), [$this->fighter->id]));
        $this->assertSame('-300000', $this->balance());
        $this->assertDatabaseCount('exemption_versions', 2);
    }

    public function test_late_joiner_is_charged_and_debt_does_not_block_rsvp(): void
    {
        $this->activate();
        $this->clock('2026-11-15T12:00:00Z');
        $new = $this->join('Новый');
        app(OpeningBalances::class)->set($this->club, $this->owner, $this->key(), ['target' => 'member', 'membership_id' => $new->id, 'amount_minor' => '-1500000', 'currency' => 'RUB', 'date' => '2026-11-15', 'reason' => 'Старый долг', 'previous_event_id' => null]);
        $t = $this->training('2026-11-15T18:00');
        app(Trainings::class)->rsvp($this->club, User::findOrFail($new->user_id), $this->key(), $t['id'], ['response' => 'yes']);
        $this->clock('2026-11-15T20:00:00Z');
        $this->close($this->draft($t, [$new->id]));
        $this->assertSame('-1750000', $this->balance($new));
    }

    public function test_frozen_and_excluded_cannot_join_new_trainings_but_draft_history_is_preserved(): void
    {
        $this->activate();
        $t = $this->draft($this->training(), [$this->fighter->id]);
        $actor = User::findOrFail($this->fighter->user_id);
        app(ClubService::class)->status($this->owner, $this->club, $this->fighter->id, ['status' => 'frozen', 'reason' => 'Пауза', 'frozen_until' => null]);
        $this->failsWith(403, fn () => app(Trainings::class)->rsvp($this->club, $actor, $this->key(), $t['id'], ['response' => 'yes']));
        $this->clock('2026-11-11T20:00:00Z');
        $this->close($t);
        $this->assertSame('-250000', $this->balance());
        app(ClubService::class)->status($this->owner, $this->club, $this->fighter->id, ['status' => 'excluded', 'reason' => 'Исключение']);
        $this->failsWith(404, fn () => app(Trainings::class)->rsvp($this->club, $actor, $this->key(), $t['id'], ['response' => 'yes']));
        $this->assertSame('-250000', $this->balance());
    }

    public function test_assigned_member_can_close_only_own_training_without_financial_access(): void
    {
        $this->activate();
        $actor = User::findOrFail($this->fighter->user_id);
        $own = $this->training('2026-11-11T18:00', $this->fighter->id);
        $other = $this->training('2026-11-12T18:00');
        $own = $this->draft($own, [$this->fighter->id], $actor);
        $this->failsWith(403, fn () => $this->draft($other, [$this->fighter->id], $actor));
        $this->clock('2026-11-11T20:00:00Z');
        $this->close($own, $actor);
        $this->assertSame('-250000', $this->balance());
        $this->actingAs($actor)->getJson('/api/clubs/'.$this->club->id.'/finance/summary')->assertForbidden();
        $this->getJson('/api/clubs/'.$this->club->id.'/billing')->assertForbidden();
        $this->assertNull(app(TrainingRead::class)->show($this->club, $actor, $other['id'])['roster']);
    }

    public function test_closed_training_is_immutable_and_repeat_close_does_not_double_charge(): void
    {
        $this->activate();
        $t = $this->draft($this->training(), [$this->fighter->id]);
        $this->clock('2026-11-11T20:00:00Z');
        $key = $this->key();
        $first = app(Trainings::class)->close($this->club, $this->owner, $key, $t['id'], ['version' => $t['version']]);
        $this->assertEquals($first, app(Trainings::class)->close($this->club, $this->owner, $key, $t['id'], ['version' => $t['version']]));
        $this->assertSame($first, $this->close($t));
        $this->failsWith(409, fn () => app(Trainings::class)->update($this->club, $this->owner, $this->key(), $t['id'], ['version' => $first['version'], 'title' => 'Перенос', 'local_start' => '2026-12-11T18:00', 'responsible_membership_id' => $this->member->id]));
        $this->failsWith(409, fn () => app(Trainings::class)->cancel($this->club, $this->owner, $this->key(), $t['id'], ['version' => $first['version'], 'reason' => 'Отмена после закрытия']));
        $this->failsWith(409, fn () => $this->draft($first, []));
        $this->assertSame('-250000', $this->balance());
        $this->assertSame(1, DB::table('financial_events')->where('type', 'charge')->count());
    }

    public function test_stale_drafts_and_future_closing_are_rejected(): void
    {
        $t = $this->training();
        $new = $this->draft($t, [$this->fighter->id]);
        $this->failsWith(409, fn () => $this->draft($t, []));
        $this->failsWith(409, fn () => $this->close($t));
        $this->failsWith(422, fn () => $this->close($new));
        $this->assertDatabaseHas('training_attendances', ['draft_present' => true, 'confirmed_at' => null]);
    }

    public function test_move_keeps_rsvps_and_cancel_creates_durable_notification_fact_without_charge(): void
    {
        $t = $this->training();
        app(Trainings::class)->rsvp($this->club, User::findOrFail($this->fighter->user_id), $this->key(), $t['id'], ['response' => 'yes']);
        $moved = app(Trainings::class)->update($this->club, $this->owner, $this->key(), $t['id'], ['version' => $t['version'], 'title' => 'Перенесена', 'local_start' => '2026-11-12T18:00', 'responsible_membership_id' => $this->fighter->id]);
        $this->assertDatabaseHas('training_rsvps', ['training_id' => $t['id'], 'membership_id' => $this->fighter->id, 'response' => 'yes']);
        $cancelled = app(Trainings::class)->cancel($this->club, $this->owner, $this->key(), $t['id'], ['version' => $moved['version'], 'reason' => 'Заранее отменена']);
        $this->assertSame('cancelled', $cancelled['status']);
        $payload = json_decode(DB::table('training_outbox')->where('topic', 'training.cancelled')->value('payload'), true);
        $this->assertContains($this->fighter->id, $payload['recipient_membership_ids']);
        $this->assertDatabaseCount('financial_events', 0);
        $past = $this->training('2026-10-23T18:00');
        $this->failsWith(409, fn () => app(Trainings::class)->cancel($this->club, $this->owner, $this->key(), $past['id'], ['version' => 1, 'reason' => 'Поздняя отмена']));
    }

    public function test_templates_generate_without_duplicates_and_edits_do_not_move_closed_occurrences(): void
    {
        $input = ['title' => 'Регулярная', 'weekdays' => [1, 3], 'local_time' => '18:00', 'responsible_membership_id' => $this->member->id, 'active' => true];
        $template = app(Trainings::class)->template($this->club, $this->owner, $this->key(), $input);
        $range = ['from' => '2026-11-09', 'to' => '2026-11-15'];
        $created = app(Trainings::class)->generate($this->club, $this->owner, $this->key(), $template['id'], $range);
        $this->assertCount(2, $created['created_ids']);
        $this->assertSame([], app(Trainings::class)->generate($this->club, $this->owner, $this->key(), $template['id'], $range)['created_ids']);
        $id = $created['created_ids'][0];
        $this->clock('2026-11-16T20:00:00Z');
        $this->close($this->draft(['id' => $id, 'version' => 1], [$this->fighter->id]));
        $old = DB::table('trainings')->find($id);
        app(Trainings::class)->template($this->club, $this->owner, $this->key(), array_replace($input, ['title' => 'Новое название', 'local_time' => '20:00', 'responsible_membership_id' => $this->fighter->id, 'version' => 1]), $template['id']);
        $current = DB::table('trainings')->find($id);
        $this->assertSame($old->starts_at, $current->starts_at);
        $this->assertSame($old->responsible_membership_id, $current->responsible_membership_id);
        $this->assertSame('closed', $current->status);
    }

    public function test_failure_during_charge_rolls_back_confirmed_attendance_obligation_audit_and_outbox(): void
    {
        $this->activate();
        $t = $this->draft($this->training(), [$this->fighter->id]);
        $this->clock('2026-11-11T20:00:00Z');
        $before = DB::table('club_actions')->count();
        $fail = true;
        DB::listen(function (QueryExecuted $q) use (&$fail) {
            if ($fail && str_starts_with($q->sql, 'insert into `financial_outbox`')) {
                $fail = false;
                throw new \RuntimeException('Injected billing failure');
            }
        });
        try {
            $this->close($t);
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected billing failure', $e->getMessage());
        }
        $this->assertDatabaseHas('trainings', ['id' => $t['id'], 'status' => 'planned', 'version' => $t['version']]);
        $this->assertDatabaseHas('training_attendances', ['training_id' => $t['id'], 'draft_present' => true, 'confirmed_at' => null]);
        foreach (['subscription_obligations', 'financial_events', 'member_entries', 'financial_outbox', 'financial_requests'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($before, DB::table('club_actions')->count());
        $this->assertSame(0, DB::table('audit_events')->where('event', 'training.closed')->count());
        $this->close($t);
        $this->assertSame('-250000', $this->balance());
    }

    public function test_foreign_club_member_and_training_are_denied(): void
    {
        $other = app(ClubService::class)->create($this->operator, User::factory()->create(), ['name' => 'Другой', 'owner_name' => 'Другой', 'timezone' => 'Europe/Moscow', 'currency' => 'RUB', 'period_start_day' => 10]);
        $t = $this->training();
        $this->failsWith(404, fn () => $this->draft($t, [$other->owner_membership_id]));
        $this->failsWith(404, fn () => app(Trainings::class)->create($this->club, $this->owner, $this->key(), ['title' => 'Чужой ответственный', 'local_start' => '2026-11-11T18:00', 'responsible_membership_id' => $other->owner_membership_id]));
        $this->actingAs($this->owner)->getJson('/api/clubs/'.$other->id.'/trainings/'.$t['id'])->assertNotFound();
        app(ClubService::class)->setPermissions($this->owner, $this->club, $this->member->id, ['schedule.manage']);
        $this->getJson('/api/clubs/'.$this->club->id.'/billing')->assertForbidden();
        $this->withHeader('Idempotency-Key', $this->key())->postJson('/api/clubs/'.$this->club->id.'/billing/tariff', ['effective_from' => '2026-11-10', 'amount_minor' => '1', 'reason' => 'Нет права'])->assertForbidden();
    }

    public function test_historical_obligation_can_be_restored_after_linked_compensation_without_second_subscription(): void
    {
        $this->activate();
        $this->clock('2026-11-15T20:00:00Z');
        $this->close($this->draft($this->training(), [$this->fighter->id]));
        $obligation = DB::table('subscription_obligations')->first();
        DB::transaction(function () use ($obligation) {
            $this->club = Club::lockForUpdate()->findOrFail($this->club->id);
            app(Journal::class)->post($this->club, $this->owner, $this->key(), 'test_correction', 'approved-removal', ['currency' => 'RUB', 'occurred_at' => '2026-11-15T19:00:00Z', 'reason' => 'Подготовка утвержденной компенсации для проверки восстановления'], fn (Club $c) => app(ClubAccess::class)->authorize($this->owner, $c, 'finance.manage'), fn () => Posting::reverseCharge($this->fighter->id, 250000, $obligation->charge_event_id));
            DB::table('subscription_obligations')->where('id', $obligation->id)->update(['current_amount_minor' => 0]);
        });
        $this->assertSame('0', $this->balance());
        $this->close($this->draft($this->training('2026-11-12T18:00'), [$this->fighter->id]));
        $this->assertSame('-250000', $this->balance());
        $this->assertDatabaseCount('subscription_obligations', 1);
        $this->assertDatabaseCount('financial_events', 3);
        $this->assertDatabaseCount('money_entries', 0);
        $this->assertDatabaseHas('subscription_obligations', ['id' => $obligation->id, 'revision' => 2, 'tariff_minor' => 250000]);
    }

    public function test_local_demo_is_repeatable_and_control_balances_match_without_changing_real_clock(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $before = CarbonImmutable::now()->toISOString();
        $one = app(TrainingDemo::class)->create();
        $two = app(TrainingDemo::class)->create();
        $this->assertSame($one, $two);
        $this->assertSame($before, CarbonImmutable::now()->toISOString());
        $club = Club::findOrFail($one['club_id']);
        $owner = User::findOrFail($one['owner_id']);
        $summary = app(FinancialRead::class)->summary($club, $owner);
        $this->assertSame('3000000', $summary['total_minor']);
        $this->assertSame('900000', $summary['debt_minor']);
        $this->assertSame(3, DB::table('financial_events')->where('club_id', $club->id)->where('type', 'charge')->count());
        $this->assertSame(4, DB::table('training_attendances')->where('club_id', $club->id)->where('confirmed_present', true)->count());
    }
}
