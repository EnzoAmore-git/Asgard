<?php

namespace App\Services\Finance;

use App\Models\Club;
use App\Models\Membership;
use App\Models\User;
use App\Services\Clubs\ClubAccess;
use Illuminate\Support\Facades\DB;

final class FinancialRead
{
    public function __construct(private ClubAccess $access) {}

    public function summary(Club $club, User $actor): array
    {
        return DB::transaction(function () use ($club, $actor) {
            $this->access->authorize($actor, $club, 'finance.view');
            $members = DB::table('member_entries')->where('club_id', $club->id)->selectRaw('membership_id, SUM(amount_minor) AS balance')->groupBy('membership_id');
            $totals = DB::query()->fromSub($members, 'b')->selectRaw('COALESCE(SUM(CASE WHEN balance < 0 THEN -balance ELSE 0 END),0) AS debt, COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END),0) AS advance')->first();
            $cash = DB::table('money_entries')->where('club_id', $club->id)->selectRaw("COALESCE(SUM(amount_minor),0) AS total, COALESCE(SUM(CASE WHEN location='card' THEN amount_minor ELSE 0 END),0) AS card, COALESCE(SUM(CASE WHEN location='custody' THEN amount_minor ELSE 0 END),0) AS custody")->first();
            $holders = DB::table('money_entries as e')->join('memberships as m', 'm.id', '=', 'e.custodian_membership_id')
                ->where('e.club_id', $club->id)->where('e.location', 'custody')->groupBy('m.id', 'm.club_name')
                ->selectRaw('m.id AS membership_id, m.club_name, CAST(SUM(e.amount_minor) AS CHAR) AS amount_minor')->orderBy('m.id')->get();
            $opening = $this->opening($club, 'card', null);
            foreach ($holders as $holder) {
                $holder->membership_id = (int) $holder->membership_id;
                $holder->opening = $this->opening($club, 'custody', $holder->membership_id);
            }

            return ['currency' => $club->currency, 'scale' => (int) $club->currency_scale, 'timezone' => $club->timezone, 'local_date' => now($club->timezone)->format('Y-m-d'),
                'total_minor' => (string) $cash->total, 'card_minor' => (string) $cash->card, 'custody_minor' => (string) $cash->custody,
                'debt_minor' => (string) $totals->debt, 'advance_minor' => (string) $totals->advance,
                'card_opening' => $opening, 'holders' => $holders, 'snapshot_at' => now()->toISOString()];
        });
    }

    public function members(Club $club, User $actor): array
    {
        $this->access->authorize($actor, $club, 'finance.view');

        return DB::transaction(function () use ($club) {
            $page = Membership::where('club_id', $club->id)->orderBy('id')->paginate(50);

            return ['data' => $page->getCollection()->map(fn (Membership $m) => [
                'id' => $m->id, 'club_name' => $m->club_name, 'status' => $m->effectiveStatus(),
                'balance_minor' => $this->balance($club, $m->id), 'opening' => $this->opening($club, 'member', $m->id),
            ]), 'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null];
        });
    }

    public function own(Club $club, User $actor): array
    {
        return DB::transaction(function () use ($club, $actor) {
            // Former members retain only their own financial history, never general club data.
            $member = Membership::where('club_id', $club->id)->where('user_id', $actor->id)->firstOrFail();
            $cash = DB::table('money_entries')->where('club_id', $club->id)->where('custodian_membership_id', $member->id)
                ->selectRaw('COALESCE(SUM(amount_minor),0) AS amount')->value('amount');
            $page = DB::table('member_entries as m')->join('financial_events as e', 'e.id', '=', 'm.event_id')
                ->where('m.club_id', $club->id)->where('m.membership_id', $member->id)->orderByDesc('e.id')
                ->select(['e.id', 'e.type', 'e.reason', 'e.occurred_at', 'e.created_at', 'e.related_event_id'])
                ->selectRaw('CAST(m.amount_minor AS CHAR) AS amount_minor')->paginate(25);
            $cashPage = DB::table('money_entries as m')->join('financial_events as e', 'e.id', '=', 'm.event_id')
                ->where('m.club_id', $club->id)->where('m.custodian_membership_id', $member->id)->orderByDesc('e.id')
                ->select(['e.id', 'e.type', 'e.reason', 'e.occurred_at', 'e.created_at', 'e.related_event_id'])
                ->selectRaw('CAST(m.amount_minor AS CHAR) AS amount_minor')->paginate(25, ['*'], 'cash_page');

            return ['currency' => $club->currency, 'scale' => (int) $club->currency_scale, 'timezone' => $club->timezone,
                'balance_minor' => $this->balance($club, $member->id), 'custody_minor' => (string) $cash,
                'history' => $page->items(), 'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
                'cash_history' => $cashPage->items(), 'cash_next_page' => $cashPage->hasMorePages() ? $cashPage->currentPage() + 1 : null];
        });
    }

    public function journal(Club $club, User $actor): array
    {
        $this->access->authorize($actor, $club, 'finance.view');

        return DB::transaction(function () use ($club) {
            $page = DB::table('financial_events')->where('club_id', $club->id)->orderByDesc('id')->paginate(25);
            $ids = $page->getCollection()->pluck('id');
            $members = DB::table('member_entries as e')->join('memberships as m', 'm.id', '=', 'e.membership_id')
                ->where('e.club_id', $club->id)->whereIn('e.event_id', $ids)->select(['e.event_id', 'm.club_name'])
                ->selectRaw('CAST(e.amount_minor AS CHAR) AS amount_minor')->get()->groupBy('event_id');
            $money = DB::table('money_entries as e')->leftJoin('memberships as m', 'm.id', '=', 'e.custodian_membership_id')
                ->where('e.club_id', $club->id)->whereIn('e.event_id', $ids)->select(['e.event_id', 'e.location', 'm.club_name'])
                ->selectRaw('CAST(e.amount_minor AS CHAR) AS amount_minor')->get()->groupBy('event_id');
            $authors = DB::table('memberships')->where('club_id', $club->id)->pluck('club_name', 'user_id');

            return ['data' => $page->getCollection()->map(fn ($e) => [
                'id' => $e->id, 'type' => $e->type, 'reason' => $e->reason,
                'occurred_at' => $e->occurred_at, 'created_at' => $e->created_at, 'related_event_id' => $e->related_event_id,
                'author' => $authors[$e->actor_id] ?? 'Оператор', 'opening_value_minor' => $e->opening_value_minor === null ? null : (string) $e->opening_value_minor,
                'members' => $members[$e->id] ?? [], 'money' => $money[$e->id] ?? [],
            ]), 'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null];
        });
    }

    private function balance(Club $club, int $member): string
    {
        return (string) DB::table('member_entries')->where('club_id', $club->id)->where('membership_id', $member)
            ->selectRaw('COALESCE(SUM(amount_minor),0) AS amount')->value('amount');
    }

    private function opening(Club $club, string $target, ?int $member): ?array
    {
        $event = DB::table('financial_events')->where('club_id', $club->id)->where('type', 'opening_'.$target)
            ->where('opening_membership_id', $member)->orderByDesc('id')->first();

        return $event ? ['event_id' => $event->id, 'amount_minor' => (string) $event->opening_value_minor, 'occurred_at' => $event->occurred_at] : null;
    }
}
