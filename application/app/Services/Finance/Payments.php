<?php

namespace App\Services\Finance;

use App\Models\Club;
use App\Models\User;
use App\Services\Audit;
use App\Services\Training\ClubAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class Payments
{
    public function __construct(private FinanceAction $facts, private ClubAction $actions) {}

    public function claim(Club $club, User $actor, string $key, array $input): array
    {
        $d = $this->facts->validate($club, $input, ['method' => ['required', Rule::in(['card', 'cash'])], 'custodian_membership_id' => 'nullable|integer|min:1', 'reason' => 'nullable|string|max:500']);

        return $this->actions->run($club, $actor, $key, 'payment.claim', $d, fn (Club $c) => $this->facts->access->member($actor, $c), function () use ($club, $actor, $d) {
            $m = $this->facts->access->member($actor, $club);
            $holder = $this->holder($club, $d);
            $id = DB::table('payment_claims')->insertGetId(['club_id' => $club->id, 'membership_id' => $m->id, 'custodian_membership_id' => $holder, 'method' => $d['method'], 'amount_minor' => $d['amount_minor'], 'paid_on' => $d['date'], 'comment' => $d['reason'] ?? null, 'created_at' => now()]);
            Audit::record($actor->id, 'payment.claimed', ['claim_id' => $id], $club->id);
            $this->outbox($club, $id, 'payment.claimed');

            return ['id' => $id, 'status' => 'pending'];
        });
    }

    public function receipt(Club $club, User $actor, string $key, array $input): array
    {
        $d = $this->facts->validate($club, $input, ['method' => ['required', Rule::in(['card', 'cash'])], 'membership_id' => 'present|nullable|integer|min:1', 'duplicates_acknowledged' => 'required|boolean']);
        $permission = $d['method'] === 'cash' ? 'cash.receive' : 'finance.manage';

        return $this->facts->run($club, $actor, $key, 'payment.receipt', $d, function () use ($club, $actor, $key, $d) {
            $member = isset($d['membership_id']) ? $this->facts->member($club, (int) $d['membership_id'])->id : null;
            abort_if($d['method'] === 'cash' && ! $member, 422, 'При приёме наличных выберите участника.');
            $holder = $d['method'] === 'cash' ? $this->facts->access->authorize($actor, $club, 'cash.receive')->id : null;
            $this->warnDuplicates($club, $actor, $member, $d);

            return $this->createReceipt($club, $actor, $d, $member, $holder, 'receipt:'.hash('sha256', $key));
        }, $permission);
    }

    public function review(Club $club, User $actor, string $key, int $id, array $input): array
    {
        $d = Validator::make($input, ['decision' => ['required', Rule::in(['confirm', 'reject', 'link'])], 'reason' => 'required|string|min:2|max:500', 'receipt_id' => 'nullable|integer|min:1', 'duplicates_acknowledged' => 'required|boolean'])->validate();

        return $this->actions->run($club, $actor, $key, 'payment.review', $d + ['id' => $id], function () use ($club, $actor, $id, $d) {
            $row = $this->claimRow($club, $id);
            // Linking an existing receipt remains a financial action; an assigned cash receiver can only confirm/reject their own receipt of cash.
            if ($row->method === 'cash' && $d['decision'] !== 'link') {
                $holder = $this->facts->access->authorize($actor, $club, 'cash.receive');
                abort_unless($holder->id === (int) $row->custodian_membership_id, 403, 'Приём подтверждает назначенный получатель.');
            } else {
                $this->facts->access->authorize($actor, $club, 'finance.manage');
            }
        }, function () use ($club, $actor, $id, $d) {
            $row = $this->claimRow($club, $id);
            if ($row->status !== 'pending') {
                abort_unless(($row->status === 'rejected' && $d['decision'] === 'reject') || ($row->status === 'confirmed' && $d['decision'] !== 'reject' && ($d['decision'] !== 'link' || (int) $d['receipt_id'] === (int) $row->receipt_id)), 409, 'Заявка уже рассмотрена.');

                return ['id' => $id, 'status' => $row->status, 'receipt_id' => $row->receipt_id];
            }
            $receipt = null;
            if ($d['decision'] === 'link') {
                abort_unless(! empty($d['receipt_id']), 422, 'Выберите уже внесённый платёж.');
                $r = $this->receiptRow($club, (int) $d['receipt_id']);
                abort_unless((int) $r->membership_id === (int) $row->membership_id && (string) $r->amount_minor === (string) $row->amount_minor && $r->method === $row->method && (int) $r->custodian_membership_id === (int) $row->custodian_membership_id, 422, 'Платёж должен относиться к тому же участнику, способу и исходной сумме.');
                abort_if(DB::table('payment_claims')->where('receipt_id', $r->id)->exists(), 409, 'Платёж уже связан с другой заявкой.');
                $receipt = $r->id;
            } elseif ($d['decision'] === 'confirm') {
                $fact = ['amount_minor' => (string) $row->amount_minor, 'currency' => $club->currency, 'date' => $row->paid_on, 'reason' => $d['reason'], 'method' => $row->method, 'duplicates_acknowledged' => $d['duplicates_acknowledged']];
                $this->warnDuplicates($club, $actor, (int) $row->membership_id, $fact);
                $receipt = $this->createReceipt($club, $actor, $fact, (int) $row->membership_id, $row->custodian_membership_id ? (int) $row->custodian_membership_id : null, 'claim:'.$id)['receipt_id'];
            }
            $status = $d['decision'] === 'reject' ? 'rejected' : 'confirmed';
            DB::table('payment_claims')->where('id', $id)->update(['status' => $status, 'receipt_id' => $receipt, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_reason' => $d['reason']]);
            Audit::record($actor->id, 'payment.'.$status, ['claim_id' => $id, 'receipt_id' => $receipt, 'reason' => $d['reason']], $club->id);
            $this->outbox($club, $id, 'payment.'.$status);

            return ['id' => $id, 'status' => $status, 'receipt_id' => $receipt];
        });
    }

    public function identify(Club $club, User $actor, string $key, int $id, array $input): array
    {
        $d = Validator::make($input, ['membership_id' => 'required|integer|min:1', 'version' => 'required|integer|min:1', 'reason' => 'required|string|min:2|max:500'])->validate();

        return $this->facts->run($club, $actor, $key, 'payment.identify', $d + ['id' => $id], function () use ($club, $actor, $id, $d) {
            $r = $this->receiptRow($club, $id);
            abort_unless((int) $r->version === (int) $d['version'], 409, 'Платёж уже изменился. Обновите историю.');
            $member = $this->facts->member($club, (int) $d['membership_id'])->id;
            $remaining = $this->remaining($r);
            abort_unless($remaining > 0, 422, 'Вся сумма уже отменена или возвращена.');
            $data = $d + ['currency' => $club->currency, 'date' => CarbonImmutable::now($club->timezone)->toDateString()];
            $posting = $r->membership_id ? Posting::reassign((int) $r->membership_id, $member, $remaining, $r->event_id) : Posting::identify($member, $remaining, $r->event_id);
            $event = $this->facts->post($club, $actor, 'payer:'.$id.':'.$r->version, $data, $posting);
            DB::table('payment_receipts')->where('id', $id)->update(['membership_id' => $member, 'version' => $r->version + 1, 'updated_at' => now()]);

            return ['event_id' => $event, 'receipt_id' => $id];
        });
    }

    public function refund(Club $club, User $actor, string $key, int $id, array $input): array
    {
        $d = $this->facts->validate($club, $input, ['version' => 'required|integer|min:1']);

        return $this->facts->run($club, $actor, $key, 'payment.refund', $d + ['id' => $id], function () use ($club, $actor, $id, $d) {
            $r = $this->receiptRow($club, $id);
            abort_unless((int) $r->version === (int) $d['version'], 409, 'Платёж изменился. Обновите историю.');
            abort_unless($r->membership_id, 422, 'Сначала установите плательщика.');
            $amount = Money::minor($d['amount_minor']);
            $balance = $this->facts->balance($club, 'member_entries', 'membership_id', (int) $r->membership_id);
            abort_unless($amount <= $balance && $amount <= $this->remaining($r), 422, 'Возврат превышает текущую переплату или оставшуюся сумму платежа.');
            abort_if($d['date'] < $r->paid_on, 422, 'Возврат не может быть раньше платежа.');
            $event = $this->facts->post($club, $actor, 'refund:'.$id.':'.$r->version, $d, Posting::refund((int) $r->membership_id, $amount, $r->event_id));
            DB::table('payment_receipts')->where('id', $id)->update(['refunded_minor' => Money::add((int) $r->refunded_minor, $amount), 'version' => $r->version + 1, 'updated_at' => now()]);

            return ['event_id' => $event];
        });
    }

    public function bankCancel(Club $club, User $actor, string $key, int $id, array $input): array
    {
        $d = $this->facts->validate($club, $input, ['version' => 'required|integer|min:1']);

        return $this->facts->run($club, $actor, $key, 'payment.bank_cancel', $d + ['id' => $id], function () use ($club, $actor, $id, $d) {
            $r = $this->receiptRow($club, $id);
            abort_unless((int) $r->version === (int) $d['version'], 409, 'Платёж изменился. Обновите историю.');
            abort_unless($r->method === 'card', 422, 'Банковская отмена относится к поступлению на карту.');
            $amount = Money::minor($d['amount_minor']);
            abort_unless($amount <= $this->remaining($r), 422, 'Сумма превышает ещё не отменённую и не возвращённую часть платежа.');
            abort_if($d['date'] < $r->paid_on, 422, 'Отмена не может быть раньше поступления.');
            $event = $this->facts->post($club, $actor, 'bank-cancel:'.$id.':'.$r->version, $d, Posting::bankCancel($r->membership_id ? (int) $r->membership_id : null, $amount, $r->event_id));
            DB::table('payment_receipts')->where('id', $id)->update(['cancelled_minor' => Money::add((int) $r->cancelled_minor, $amount), 'version' => $r->version + 1, 'updated_at' => now()]);

            return ['event_id' => $event, 'receipt_id' => $id];
        });
    }

    private function createReceipt(Club $club, User $actor, array $d, ?int $member, ?int $holder, string $source): array
    {
        $amount = Money::minor($d['amount_minor']);
        $posting = $member ? Posting::receipt($member, $amount, $holder) : Posting::unknown($amount);
        $event = $this->facts->post($club, $actor, $source, $d, $posting);
        $id = DB::table('payment_receipts')->insertGetId(['club_id' => $club->id, 'membership_id' => $member, 'custodian_membership_id' => $holder, 'method' => $d['method'], 'amount_minor' => $amount, 'paid_on' => $d['date'], 'event_id' => $event, 'created_at' => now(), 'updated_at' => now()]);

        return ['receipt_id' => $id, 'event_id' => $event];
    }

    private function holder(Club $club, array $d): ?int
    {
        if ($d['method'] === 'card') {
            abort_if(! empty($d['custodian_membership_id']), 422);

            return null;
        }
        abort_unless(! empty($d['custodian_membership_id']), 422, 'Укажите, кому переданы наличные.');
        $m = $this->facts->member($club, (int) $d['custodian_membership_id']);
        abort_unless(in_array('cash.receive', $this->facts->access->capabilities($m, $club), true), 422, 'Получателю не назначено право приёма наличных.');

        return $m->id;
    }

    public function receiptRow(Club $club, int $id): object
    {
        $r = DB::table('payment_receipts')->where('club_id', $club->id)->find($id);
        abort_unless($r, 404);

        return $r;
    }

    private function claimRow(Club $club, int $id): object
    {
        $r = DB::table('payment_claims')->where('club_id', $club->id)->find($id);
        abort_unless($r, 404);

        return $r;
    }

    private function remaining(object $r): int
    {
        return Money::add(Money::add(Money::minor((string) $r->amount_minor), -Money::minor((string) $r->cancelled_minor)), -Money::minor((string) $r->refunded_minor));
    }

    private function outbox(Club $club, int $id, string $topic): void
    {
        DB::table('payment_outbox')->insert(['club_id' => $club->id, 'claim_id' => $id, 'topic' => $topic, 'payload' => json_encode(['claim_id' => (string) $id], JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    private function warnDuplicates(Club $club, User $actor, ?int $member, array $d): void
    {
        if ($member === null) {
            return;
        }
        $same = DB::table('payment_receipts')->where('club_id', $club->id)->where('membership_id', $member)->where('amount_minor', $d['amount_minor']);
        $actorMember = $this->facts->access->member($actor, $club);
        $restricted = ! in_array('finance.view', $this->facts->access->capabilities($actorMember, $club), true);
        if ($restricted) {
            $same->where('custodian_membership_id', $actorMember->id);
        }
        if (! $same->exists()) {
            return;
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $d['date']);
        $window = (int) config('finance.duplicate_window_days', 3);
        $query = DB::table('payment_receipts')->where('club_id', $club->id)->where('membership_id', $member)->where('amount_minor', $d['amount_minor'])->whereBetween('paid_on', [$date->subDays($window)->toDateString(), $date->addDays($window)->toDateString()]);
        if ($restricted) {
            $query->where('custodian_membership_id', $actorMember->id);
        }
        abort_if($query->exists() && ! $d['duplicates_acknowledged'], 409, 'Похожая оплата уже есть. Проверьте историю. Если деньги поступили ещё раз, подтвердите отдельный платёж.');
    }
}
