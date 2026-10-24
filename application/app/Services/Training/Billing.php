<?php

namespace App\Services\Training;

use App\Models\Club;
use App\Models\Membership;
use App\Models\User;
use App\Services\Audit;
use App\Services\Clubs\ClubAccess;
use App\Services\Finance\Journal;
use App\Services\Finance\Money;
use App\Services\Finance\Posting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class Billing
{
    public function __construct(private ClubAction $actions, private Calendar $calendar, private ClubAccess $access, private Journal $journal) {}

    public function configure(Club $club, User $actor, string $key, array $input): array
    {
        $data = Validator::make($input, ['effective_from' => 'required|date_format:Y-m-d', 'amount_minor' => 'required|string', 'reason' => 'required|string|min:2|max:500'])->validate();
        $amount = Money::minor($data['amount_minor']);
        abort_if($amount < 0, 422, 'Тариф не может быть отрицательным.');

        return $this->actions->run($club, $actor, $key, 'billing.configure', $data, fn (Club $c) => $this->access->authorize($actor, $c, 'finance.manage'), function (Club $c) use ($actor, $data, $amount) {
            $this->calendar->validateFuture($c, $data['effective_from']);
            $settings = DB::table('billing_settings')->where('club_id', $c->id)->first();
            if (! $settings) {
                DB::table('billing_settings')->insert(['club_id' => $c->id, 'active_from' => $data['effective_from'], 'actor_id' => $actor->id, 'created_at' => now()]);
            } else {
                abort_if($data['effective_from'] < $settings->active_from, 422, 'Новый тариф не может действовать раньше запуска начислений.');
            }
            $id = DB::table('tariff_versions')->insertGetId(['club_id' => $c->id, 'effective_from' => $data['effective_from'], 'amount_minor' => $amount, 'actor_id' => $actor->id, 'reason' => $data['reason'], 'created_at' => now()]);
            Audit::record($actor->id, 'billing.tariff_set', ['tariff_version_id' => $id, 'effective_from' => $data['effective_from']], $c->id);

            return ['tariff_version_id' => $id];
        });
    }

    public function exemption(Club $club, User $actor, string $key, int $member, array $input): array
    {
        $data = Validator::make($input, ['exempt' => 'required|boolean', 'effective_from' => 'required|date_format:Y-m-d', 'reason' => 'required|string|min:2|max:500'])->validate();
        $data['membership_id'] = $member;

        return $this->actions->run($club, $actor, $key, 'billing.exemption', $data, fn (Club $c) => $this->access->authorize($actor, $c, 'finance.manage'), function (Club $c) use ($actor, $member, $data) {
            Membership::where('club_id', $c->id)->findOrFail($member);
            $settings = DB::table('billing_settings')->where('club_id', $c->id)->first();
            abort_unless($settings, 422, 'Сначала задайте дату запуска начислений.');
            $from = max($this->calendar->futureStart($c), $settings->active_from);
            abort_unless($data['effective_from'] === $from, 409, 'Доступный период изменился. Обновите настройки.');
            $id = DB::table('exemption_versions')->insertGetId(['club_id' => $c->id, 'membership_id' => $member, 'effective_from' => $from, 'exempt' => (bool) $data['exempt'], 'actor_id' => $actor->id, 'reason' => $data['reason'], 'created_at' => now()]);
            Audit::record($actor->id, 'billing.exemption_set', ['exemption_version_id' => $id, 'membership_id' => $member, 'effective_from' => $from], $c->id);

            return ['exemption_version_id' => $id];
        });
    }

    public function read(Club $club, User $actor): array
    {
        $this->access->authorize($actor, $club, 'finance.view');

        return DB::transaction(function () use ($club) {
            $settings = DB::table('billing_settings')->where('club_id', $club->id)->first();
            $page = Membership::where('club_id', $club->id)->orderBy('id')->paginate(50);
            $next = $this->calendar->futureStart($club);
            $from = $settings ? max($next, $settings->active_from) : $next;
            $data = $page->getCollection()->map(function ($m) use ($club, $from) {
                $e = DB::table('exemption_versions')->where('club_id', $club->id)->where('membership_id', $m->id)->where('effective_from', '<=', $from)->orderByDesc('effective_from')->orderByDesc('id')->first();

                return ['id' => $m->id, 'club_name' => $m->club_name, 'exempt_next' => (bool) ($e?->exempt ?? false)];
            });
            $tariffs = DB::table('tariff_versions')->where('club_id', $club->id)->orderByDesc('effective_from')->orderByDesc('id')->limit(30)->get(['id', 'effective_from', 'amount_minor', 'reason', 'created_at'])->map(function ($r) {
                $r->amount_minor = (string) $r->amount_minor;

                return $r;
            });

            return ['currency' => $club->currency, 'scale' => (int) $club->currency_scale, 'timezone' => $club->timezone, 'nominal_day' => (int) $club->period_start_day, 'active_from' => $settings?->active_from, 'next_period' => $next, 'exemption_from' => $from, 'tariffs' => $tariffs, 'members' => $data, 'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null];
        });
    }

    /** Called only in the closing transaction, after confirmed attendance is fixed. */
    public function attend(Club $club, User $actor, object $training, object $period, int $member, \Closure $authorize): void
    {
        abort_unless($training->club_id === $club->id && $training->status === 'closed' && $training->period_id === $period->id
            && DB::table('training_attendances')->where('club_id', $club->id)->where('training_id', $training->id)->where('membership_id', $member)->where('confirmed_present', true)->whereNotNull('confirmed_at')->exists(), 409, 'Начисление требует подтвержденной явки в закрытой ведомости.');
        $settings = DB::table('billing_settings')->where('club_id', $club->id)->first();
        if (! $settings || $period->starts_on < $settings->active_from) {
            return;
        }
        $q = DB::table('subscription_obligations')->where('club_id', $club->id)->where('membership_id', $member)->where('period_id', $period->id);
        $obligation = $q->first();
        if (! $obligation) {
            $tariff = DB::table('tariff_versions')->where('club_id', $club->id)->where('effective_from', '<=', $period->starts_on)->orderByDesc('effective_from')->orderByDesc('id')->first();
            abort_unless($tariff, 409, 'Для этого периода не найден тариф. Ведомость не закрыта.');
            $exempt = DB::table('exemption_versions')->where('club_id', $club->id)->where('membership_id', $member)->where('effective_from', '<=', $period->starts_on)->orderByDesc('effective_from')->orderByDesc('id')->first();
            $id = DB::table('subscription_obligations')->insertGetId(['club_id' => $club->id, 'period_id' => $period->id, 'membership_id' => $member, 'tariff_version_id' => $tariff->id, 'tariff_minor' => $tariff->amount_minor, 'exempt' => (bool) ($exempt?->exempt ?? false), 'current_amount_minor' => 0, 'revision' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $obligation = DB::table('subscription_obligations')->find($id);
        }
        $amount = $obligation->exempt ? 0 : Money::minor((string) $obligation->tariff_minor);
        if ($amount === 0 || (string) $obligation->current_amount_minor === (string) $amount) {
            return;
        }
        abort_unless((string) $obligation->current_amount_minor === '0', 409, 'Требуется финансовая проверка обязательства.');
        $revision = $obligation->revision + 1;
        $source = $obligation->id.':'.$revision;
        $event = $this->journal->post($club, $actor, 'subscription:'.hash('sha256', $source), 'subscription_revision', $source,
            ['currency' => $club->currency, 'occurred_at' => $training->starts_at, 'reason' => 'Взнос за период '.$period->starts_on.' — '.CarbonImmutable::parse($period->ends_on, $club->timezone)->subDay()->toDateString(), 'amount_minor' => (string) $amount, 'training_id' => $training->id], $authorize, fn () => Posting::charge($member, $amount));
        DB::table('subscription_obligations')->where('id', $obligation->id)->update(['current_amount_minor' => $amount, 'revision' => $revision, 'charge_event_id' => $event->id, 'updated_at' => now()]);
    }
}
