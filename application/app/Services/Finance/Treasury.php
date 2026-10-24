<?php

namespace App\Services\Finance;

use App\Models\Club;
use App\Models\User;
use App\Services\Audit;
use App\Services\Training\Calendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class Treasury
{
    public function __construct(private FinanceAction $facts, private Calendar $calendar) {}

    public function transfer(Club $club, User $actor, string $key, array $input): array
    {
        $d = $this->facts->validate($club, $input, ['custodian_membership_id' => 'required|integer|min:1']);

        return $this->facts->run($club, $actor, $key, 'cash.transfer', $d, function () use ($club, $actor, $key, $d) {
            $this->facts->member($club, (int) $d['custodian_membership_id']);

            return ['event_id' => $this->facts->post($club, $actor, 'transfer:'.hash('sha256', $key), $d, Posting::transfer((int) $d['custodian_membership_id'], Money::minor($d['amount_minor'])))];
        });
    }

    public function refundOpening(Club $club, User $actor, string $key, array $input): array
    {
        $d = $this->facts->validate($club, $input, ['membership_id' => 'required|integer|min:1', 'opening_event_id' => 'required|integer|min:1']);

        return $this->facts->run($club, $actor, $key, 'opening.refund', $d, function () use ($club, $actor, $key, $d) {
            $member = $this->facts->member($club, (int) $d['membership_id']);
            $opening = DB::table('financial_events')->where('club_id', $club->id)->where('type', 'opening_member')->where('opening_membership_id', $member->id)->orderByDesc('id')->first();
            abort_unless($opening && $opening->id === (int) $d['opening_event_id'], 409, 'Начальные данные изменились. Обновите страницу.');
            $returned = Money::minor((string) DB::table('member_entries as m')->join('financial_events as e', 'e.id', '=', 'm.event_id')->where('m.club_id', $club->id)->where('m.membership_id', $member->id)->where('e.source_type', 'opening_refund')->selectRaw('COALESCE(-SUM(m.amount_minor),0) AS amount')->value('amount'));
            $amount = Money::minor($d['amount_minor']);
            abort_unless($amount <= $this->facts->balance($club, 'member_entries', 'membership_id', $member->id) && $amount <= Money::add(Money::minor((string) $opening->opening_value_minor), -$returned), 422, 'Возврат превышает текущую переплату или ещё не возвращённый начальный аванс.');
            $d['occurred_at'] = CarbonImmutable::createFromFormat('!Y-m-d', $d['date'], $club->timezone)->utc()->toISOString();
            abort_if(CarbonImmutable::parse($d['occurred_at'])->lt(CarbonImmutable::parse($opening->occurred_at, 'UTC')), 422, 'Возврат не может быть раньше начальных данных.');
            $source = 'opening-refund:'.hash('sha256', $key);
            $event = app(Journal::class)->post($club, $actor, $source, 'opening_refund', $source, $d, fn () => null, fn () => Posting::refund($member->id, $amount, $opening->id));

            return ['event_id' => $event->id];
        });
    }

    public function expense(Club $club, User $actor, string $key, array $input, ?int $occurrence = null): array
    {
        $d = $this->facts->validate($club, $input);

        return $this->facts->run($club, $actor, $key, 'expense.pay', $d + ['occurrence' => $occurrence], function () use ($club, $actor, $key, $d, $occurrence) {
            if ($occurrence) {
                $row = DB::table('expense_occurrences')->where('club_id', $club->id)->find($occurrence);
                abort_unless($row, 404);
                if ($row->event_id) {
                    return ['event_id' => $row->event_id];
                }
            }
            $event = $this->facts->post($club, $actor, $occurrence ? 'expense:'.$occurrence : 'expense:'.hash('sha256', $key), $d, Posting::expense(Money::minor($d['amount_minor'])));
            if ($occurrence) {
                DB::table('expense_occurrences')->where('id', $occurrence)->update(['event_id' => $event]);
            }

            return ['event_id' => $event];
        });
    }

    public function template(Club $club, User $actor, string $key, array $input, ?int $id = null): array
    {
        $d = Validator::make($input, ['title' => 'required|string|min:2|max:150', 'amount_minor' => 'required|string', 'currency' => 'required|string|size:3', 'day_of_month' => 'required|integer|min:1|max:31', 'active' => 'required|boolean', 'version' => 'required|integer|min:1'])->validate();
        abort_unless($d['currency'] === $club->currency && Money::minor($d['amount_minor']) > 0, 422, 'Проверьте сумму и валюту.');

        return $this->facts->run($club, $actor, $key, 'expense.template', $d + ['id' => $id], function () use ($club, $actor, $d, $id) {
            if ($id) {
                $old = DB::table('expense_templates')->where('club_id', $club->id)->find($id);
                abort_unless($old, 404);
                abort_unless((int) $old->version === (int) $d['version'], 409, 'Шаблон уже изменён. Обновите страницу.');
            }
            $values = ['title' => $d['title'], 'amount_minor' => $d['amount_minor'], 'day_of_month' => $d['day_of_month'], 'active' => $d['active'], 'updated_at' => now(), 'version' => $id ? $old->version + 1 : 1];
            if ($id) {
                DB::table('expense_templates')->where('id', $id)->update($values);
            } else {
                $id = DB::table('expense_templates')->insertGetId($values + ['club_id' => $club->id, 'created_at' => now()]);
            }
            Audit::record($actor->id, 'expense.template.saved', ['template_id' => $id], $club->id);

            return ['id' => $id];
        });
    }

    public function generate(Club $club, User $actor, string $key, int $id, array $input): array
    {
        $d = Validator::make($input, ['month' => 'required|date_format:Y-m'])->validate();

        return $this->facts->run($club, $actor, $key, 'expense.generate', $d + ['id' => $id], function () use ($club, $actor, $d, $id) {
            $template = DB::table('expense_templates')->where('club_id', $club->id)->find($id);
            abort_unless($template, 404);
            abort_unless($template->active, 422, 'Шаблон выключен.');
            $month = CarbonImmutable::createFromFormat('!Y-m', $d['month'], $club->timezone);
            $due = $month->day(min($template->day_of_month, $month->daysInMonth))->toDateString();
            $old = DB::table('expense_occurrences')->where('template_id', $id)->where('occurrence_month', $d['month'])->first();
            $occ = $old?->id ?? DB::table('expense_occurrences')->insertGetId(['club_id' => $club->id, 'template_id' => $id, 'due_on' => $due, 'occurrence_month' => $d['month'], 'title' => $template->title, 'amount_minor' => $template->amount_minor, 'created_at' => now()]);
            Audit::record($actor->id, 'expense.expected', ['occurrence_id' => $occ], $club->id);

            return ['id' => $occ];
        });
    }

    public function reconcile(Club $club, User $actor, string $key, array $input): array
    {
        $d = Validator::make($input, ['actual_minor' => 'required|string', 'as_of' => 'required|date_format:Y-m-d\TH:i', 'comment' => 'required|string|min:2|max:500'])->validate();
        Money::minor($d['actual_minor']);
        $instant = $this->calendar->localTime($club, $d['as_of']);
        abort_if($instant->isFuture(), 422, 'Выберите прошедший момент сверки.');

        return $this->facts->run($club, $actor, $key, 'card.reconcile', $d, function () use ($club, $actor, $d, $instant) {
            $calc = $this->cardAt($club, $instant->toISOString());
            $difference = Money::add(Money::minor($d['actual_minor']), -$calc);
            $id = DB::table('card_reconciliations')->insertGetId(['club_id' => $club->id, 'actor_id' => $actor->id, 'actual_minor' => $d['actual_minor'], 'calculated_minor' => $calc, 'difference_minor' => $difference, 'as_of' => $instant, 'created_at' => now(), 'comment' => $d['comment']]);
            Audit::record($actor->id, 'card.reconciled', ['reconciliation_id' => $id], $club->id);

            return ['id' => $id, 'calculated_minor' => (string) $calc, 'difference_minor' => (string) $difference];
        });
    }

    public function adjustment(Club $club, User $actor, string $key, int $id, array $input): array
    {
        $d = Validator::make($input, ['expected_calculated_minor' => 'required|string', 'reason' => 'required|string|min:2|max:500', 'currency' => 'required|string|size:3'])->validate();
        Money::minor($d['expected_calculated_minor']);
        abort_unless($d['currency'] === $club->currency, 422);

        return $this->facts->run($club, $actor, $key, 'card.adjust', $d + ['id' => $id], function () use ($club, $actor, $id, $d) {
            $row = DB::table('card_reconciliations')->where('club_id', $club->id)->find($id);
            abort_unless($row, 404);
            abort_if(DB::table('financial_events')->where('club_id', $club->id)->where('source_type', 'finance_action')->where('source_id', 'reconciliation:'.$id)->exists(), 409, 'Для этой сверки исправление уже внесено.');
            $calc = $this->cardAt($club, $row->as_of);
            abort_unless($calc === Money::minor($d['expected_calculated_minor']), 409, 'Расчёт изменился. Обновите сверку.');
            $delta = Money::add(Money::minor((string) $row->actual_minor), -$calc);
            abort_if($delta === 0, 422, 'Расхождения уже нет.');
            // Exact as-of instant, unlike date-only manual receipts.
            $event = app(Journal::class)->post($club, $actor, 'reconcile:'.hash('sha256', (string) $id), 'finance_action', 'reconciliation:'.$id, $d + ['occurred_at' => CarbonImmutable::parse($row->as_of, 'UTC')->toISOString()], fn () => null, fn () => Posting::adjustment($delta));

            return ['event_id' => $event->id];
        });
    }

    public function cardAt(Club $club, string $instant): int
    {
        return Money::minor((string) DB::table('money_entries as m')->join('financial_events as e', 'e.id', '=', 'm.event_id')->where('m.club_id', $club->id)->where('m.location', 'card')->where('e.occurred_at', '<=', CarbonImmutable::parse($instant, 'UTC')->utc())->selectRaw('COALESCE(SUM(m.amount_minor),0) AS amount')->value('amount'));
    }
}
