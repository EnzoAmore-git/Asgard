<?php

namespace App\Services\Finance;

use App\Models\Club;
use App\Models\Membership;
use App\Models\User;
use App\Services\Clubs\ClubAccess;
use Illuminate\Support\Facades\DB;

final class PaymentRead
{
    public function __construct(private ClubAccess $access, private Treasury $treasury) {}

    public function options(Club $club, User $actor): array
    {
        $m = $this->access->member($actor, $club);
        $caps = $this->access->capabilities($m, $club);
        $receivers = DB::table('permission_grants as p')->join('memberships as m', 'm.id', '=', 'p.membership_id')->where('p.club_id', $club->id)->where('p.permission', 'cash.receive')->where('m.status', '!=', 'excluded')->orderBy('m.id')->get(['m.id', 'm.club_name']);
        $people = null;
        $next = null;
        if (in_array('finance.view', $caps, true) || in_array('cash.receive', $caps, true)) {
            $page = Membership::where('club_id', $club->id)->orderBy('id')->paginate(50);
            $people = $page->getCollection()->map(fn ($r) => ['id' => $r->id, 'club_name' => $r->club_name, 'opening_refund' => in_array('finance.view', $caps, true) ? $this->openingRefund($club, $r->id) : null]);
            $next = $page->hasMorePages() ? $page->currentPage() + 1 : null;
        }

        return ['currency' => $club->currency, 'scale' => (int) $club->currency_scale, 'local_date' => now($club->timezone)->toDateString(), 'local_datetime' => now($club->timezone)->format('Y-m-d\\TH:i'), 'timezone' => $club->timezone, 'receivers' => $receivers, 'members' => $people, 'next_page' => $next];
    }

    public function claims(Club $club, User $actor, string $scope): array
    {
        $m = $this->access->member($actor, $club);
        $caps = $this->access->capabilities($m, $club);
        $query = DB::table('payment_claims as c')->join('memberships as m', 'm.id', '=', 'c.membership_id')->leftJoin('memberships as h', 'h.id', '=', 'c.custodian_membership_id')->where('c.club_id', $club->id);
        if ($scope === 'inbox') {
            if (! in_array('finance.view', $caps, true)) {
                $this->access->authorize($actor, $club, 'cash.receive');
                $query->where('c.method', 'cash')->where('c.custodian_membership_id', $m->id);
            }
        } else {
            $query->where('c.membership_id', $m->id);
        }
        $page = $query->select(['c.id', 'c.membership_id', 'c.custodian_membership_id', 'm.club_name', 'h.club_name as receiver_name', 'c.method', 'c.paid_on', 'c.status', 'c.receipt_id', 'c.comment', 'c.review_reason', 'c.created_at'])->selectRaw('CAST(c.amount_minor AS CHAR) AS amount_minor')->orderByDesc('c.id')->paginate(25);

        $rows = $page->getCollection()->map(function ($row) use ($scope, $caps) {
            $row->has_attachment = ($scope !== 'inbox' || in_array('finance.view', $caps, true)) && DB::table('payment_attachments')->where('claim_id', $row->id)->exists();
            if ($scope === 'inbox' && ! in_array('finance.view', $caps, true)) {
                unset($row->comment,$row->review_reason);
            }

            return $row;
        });

        return ['data' => $rows, 'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null];
    }

    public function receipts(Club $club, User $actor): array
    {
        $m = $this->access->member($actor, $club);
        $caps = $this->access->capabilities($m, $club);
        $view = in_array('finance.view', $caps, true);
        if (! $view) {
            $this->access->authorize($actor, $club, 'cash.receive');
        }
        $q = DB::table('payment_receipts as r')->leftJoin('memberships as m', 'm.id', '=', 'r.membership_id')->leftJoin('memberships as h', 'h.id', '=', 'r.custodian_membership_id')->where('r.club_id', $club->id);
        if (! $view) {
            $q->where('r.custodian_membership_id', $m->id);
        }
        $cols = ['r.id', 'm.club_name', 'h.club_name as receiver_name', 'r.paid_on', 'r.method', 'r.event_id'];
        if ($view) {
            $cols = [...$cols, 'r.membership_id', 'r.version'];
        }
        $page = $q->select($cols)->selectRaw('CAST(r.amount_minor AS CHAR) AS amount_minor')->orderByDesc('r.id')->paginate(25);
        $rows = $page->getCollection()->map(function ($r) use ($club, $view) {
            $claim = DB::table('payment_claims')->where('club_id', $club->id)->where('receipt_id', $r->id)->first(['id']);
            $r->claim_id = $claim?->id;
            if ($view) {
                $source = DB::table('payment_receipts')->find($r->id);
                $r->cancelled_minor = (string) $source->cancelled_minor;
                $r->refunded_minor = (string) $source->refunded_minor;
                $r->remaining_minor = (string) ((int) $source->amount_minor - (int) $source->cancelled_minor - (int) $source->refunded_minor);
            }

            return $r;
        });

        return ['data' => $rows, 'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null];
    }

    public function expenses(Club $club, User $actor): array
    {
        $this->access->authorize($actor, $club, 'finance.view');
        $page = DB::table('expense_occurrences')->where('club_id', $club->id)->orderByDesc('due_on')->orderByDesc('id')->select(['id', 'template_id', 'due_on', 'title', 'event_id'])->selectRaw('CAST(amount_minor AS CHAR) AS amount_minor')->paginate(25);
        $templates = DB::table('expense_templates')->where('club_id', $club->id)->orderBy('id')->get(['id', 'title', 'amount_minor', 'day_of_month', 'active', 'version'])->map(function ($r) {
            $r->amount_minor = (string) $r->amount_minor;

            return $r;
        });

        return ['data' => $page->items(), 'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null, 'templates' => $templates];
    }

    public function reconciliations(Club $club, User $actor): array
    {
        $this->access->authorize($actor, $club, 'finance.view');
        $page = DB::table('card_reconciliations')->where('club_id', $club->id)->orderByDesc('id')->paginate(25);

        return ['data' => $page->getCollection()->map(function ($r) use ($club) {
            $current = $this->treasury->cardAt($club, $r->as_of);

            return ['id' => $r->id, 'actual_minor' => (string) $r->actual_minor, 'calculated_minor' => (string) $r->calculated_minor, 'difference_minor' => (string) $r->difference_minor, 'current_calculated_minor' => (string) $current, 'current_difference_minor' => (string) Money::add(Money::minor((string) $r->actual_minor), -$current), 'as_of' => $r->as_of, 'created_at' => $r->created_at, 'comment' => $r->comment];
        }), 'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null];
    }

    private function openingRefund(Club $club, int $member): ?array
    {
        $row = DB::table('financial_events')->where('club_id', $club->id)->where('type', 'opening_member')->where('opening_membership_id', $member)->orderByDesc('id')->first();
        if (! $row || (int) $row->opening_value_minor <= 0) {
            return null;
        }

        return ['event_id' => $row->id, 'amount_minor' => (string) $row->opening_value_minor];
    }
}
