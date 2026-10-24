<?php

namespace App\Services\Finance;

use App\Models\Club;
use App\Models\Membership;
use App\Models\User;
use App\Services\Audit;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Trusted application service. Callers supply authorization and source changes inside this transaction. */
final class Journal
{
    public function post(Club $club, User $actor, string $key, string $sourceType, string $sourceId, array $parameters, Closure $authorize, Closure $prepare): object
    {
        Validator::make(compact('key', 'sourceType', 'sourceId'), [
            'key' => 'required|string|min:16|max:128', 'sourceType' => 'required|string|max:48', 'sourceId' => 'required|string|max:191',
        ])->validate();
        $keyHash = hash('sha256', $key);
        $fingerprint = hash('sha256', json_encode($this->canonical([
            'actor' => $actor->id, 'club' => $club->id, 'source_type' => $sourceType, 'source_id' => $sourceId, 'parameters' => $parameters,
        ]), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($club, $actor, $keyHash, $fingerprint, $sourceType, $sourceId, $parameters, $authorize, $prepare) {
            // Shared lock order with membership/permission changes: club first.
            $lockedClub = Club::lockForUpdate()->findOrFail($club->id);
            $authorize($lockedClub);
            abort_unless(($parameters['currency'] ?? null) === $lockedClub->currency, 422, 'Валюта операции должна совпадать с валютой клуба.');
            $request = DB::table('financial_requests')->where('club_id', $club->id)->where('key_hash', $keyHash)->first();
            if ($request) {
                abort_unless(hash_equals($request->request_hash, $fingerprint), 409, 'Этот запрос уже использован с другими данными. Обновите страницу.');

                return DB::table('financial_events')->where('club_id', $club->id)->find($request->event_id);
            }
            $existing = DB::table('financial_events')->where('club_id', $club->id)->where('source_type', $sourceType)->where('source_id', $sourceId)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $fingerprint), 409, 'Это основание уже проведено с другими данными. Обновите историю.');
                $this->remember($club->id, $keyHash, $fingerprint, $existing->id);

                return $existing;
            }
            Validator::make($parameters, ['reason' => 'required|string|min:2|max:500', 'occurred_at' => 'required|date'])->validate();
            $draft = $prepare($lockedClub);
            if (! $draft instanceof Posting) {
                throw new \LogicException('A fixed posting shape is required.');
            }
            $this->validateBalances($lockedClub, $draft);
            $time = now();
            $eventId = DB::table('financial_events')->insertGetId([
                'club_id' => $club->id, 'type' => $draft->type, 'source_type' => $sourceType, 'source_id' => $sourceId,
                'request_hash' => $fingerprint, 'currency' => $lockedClub->currency, 'currency_scale' => $lockedClub->currency_scale,
                'actor_id' => $actor->id, 'related_event_id' => $draft->related,
                'opening_membership_id' => $draft->openingMember, 'opening_value_minor' => $draft->openingValue,
                'occurred_at' => Carbon::parse($parameters['occurred_at'])->utc(), 'created_at' => $time, 'reason' => $parameters['reason'],
            ]);
            foreach ($draft->members as $member => $amount) {
                DB::table('member_entries')->insert(['club_id' => $club->id, 'event_id' => $eventId, 'membership_id' => $member, 'amount_minor' => $amount]);
            }
            foreach ($draft->money as $entry) {
                DB::table('money_entries')->insert(['club_id' => $club->id, 'event_id' => $eventId] + $entry);
            }
            Audit::record($actor->id, 'finance.posted', ['event_id' => $eventId, 'type' => $draft->type], $club->id);
            // Durable domain event only. No recipient, delivery, or provider call in phase 3.
            DB::table('financial_outbox')->insert(['club_id' => $club->id, 'event_id' => $eventId, 'topic' => 'finance.posted',
                'payload' => json_encode(['event_id' => (string) $eventId], JSON_THROW_ON_ERROR), 'created_at' => $time]);
            $this->remember($club->id, $keyHash, $fingerprint, $eventId);

            return DB::table('financial_events')->where('club_id', $club->id)->find($eventId);
        }, 3);
    }

    private function validateBalances(Club $club, Posting $draft): void
    {
        foreach ($draft->members as $member => $delta) {
            Membership::where('club_id', $club->id)->findOrFail($member);
            Money::add($this->balance('member_entries', $club->id, 'membership_id', $member), $delta);
        }
        foreach ($draft->money as $entry) {
            $member = $entry['custodian_membership_id'];
            if ($member !== null) {
                Membership::where('club_id', $club->id)->findOrFail($member);
            }
            $key = $member === null ? 'card' : 'custody:'.$member;
            $balance = Money::add($this->balance('money_entries', $club->id, 'account_key', $key), $entry['amount_minor']);
            abort_if($member !== null && $balance < 0, 422, 'Сумма превышает непереданный остаток у получателя.');
        }
        if ($draft->related !== null) {
            $related = DB::table('financial_events')->where('club_id', $club->id)->find($draft->related);
            abort_unless($related, 404);
            if ($draft->type === 'charge_reversal') {
                abort_unless($related->type === 'charge', 422);
                $member = array_key_first($draft->members);
                $original = DB::table('member_entries')->where('club_id', $club->id)->where('event_id', $related->id)->where('membership_id', $member)->first();
                abort_unless($original, 422);
                $already = DB::table('member_entries as m')->join('financial_events as e', 'e.id', '=', 'm.event_id')
                    ->where('e.club_id', $club->id)->where('e.type', 'charge_reversal')->where('e.related_event_id', $related->id)
                    ->selectRaw('COALESCE(SUM(m.amount_minor),0) AS amount')->value('amount');
                abort_if(Money::add(Money::minor((string) $already), $draft->members[$member]) > -Money::minor((string) $original->amount_minor), 422, 'Начисление уже компенсировано.');
            }
        }
    }

    private function balance(string $table, int $club, string $column, int|string $target): int
    {
        $value = DB::table($table)->where('club_id', $club)->where($column, $target)->selectRaw('COALESCE(SUM(amount_minor),0) AS amount')->value('amount');

        return Money::minor((string) $value);
    }

    private function remember(int $club, string $key, string $hash, int $event): void
    {
        DB::table('financial_requests')->insert(['club_id' => $club, 'key_hash' => $key, 'request_hash' => $hash, 'event_id' => $event, 'created_at' => now()]);
    }

    private function canonical(array $data): array
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }

        return $data;
    }
}
