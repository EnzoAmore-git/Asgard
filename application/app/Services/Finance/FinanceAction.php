<?php

namespace App\Services\Finance;

use App\Models\Club;
use App\Models\Membership;
use App\Models\User;
use App\Services\Clubs\ClubAccess;
use App\Services\Training\ClubAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Shared transaction and fact validation; all public operations acquire the club lock first. */
final class FinanceAction
{
    public function __construct(public ClubAccess $access, private ClubAction $actions, private Journal $journal) {}

    public function run(Club $club, User $actor, string $key, string $action, array $data, \Closure $work, string $permission = 'finance.manage'): array
    {
        return $this->actions->run($club, $actor, $key, $action, $data, fn (Club $locked) => $this->access->authorize($actor, $locked, $permission), $work);
    }

    public function validate(Club $club, array $input, array $extra = []): array
    {
        $data = Validator::make($input, $extra + ['amount_minor' => 'required|string', 'currency' => 'required|string|size:3', 'date' => 'required|date_format:Y-m-d', 'reason' => 'required|string|min:2|max:500'])->validate();
        abort_unless($data['currency'] === $club->currency, 422, 'Валюта должна совпадать с валютой клуба.');
        $amount = Money::minor($data['amount_minor']);
        abort_unless($amount > 0, 422, 'Сумма должна быть больше нуля.');
        abort_if($data['date'] > CarbonImmutable::now($club->timezone)->toDateString(), 422, 'Будущий перевод или расход нельзя подтвердить как состоявшийся.');

        return $data;
    }

    public function member(Club $club, int $id): Membership
    {
        return Membership::where('club_id', $club->id)->findOrFail($id);
    }

    public function post(Club $club, User $actor, string $source, array $data, Posting $posting): int
    {
        $data['occurred_at'] = CarbonImmutable::createFromFormat('!Y-m-d', $data['date'], $club->timezone)->utc()->toISOString();
        $event = $this->journal->post($club, $actor, 'fact:'.hash('sha256', $source), 'finance_action', $source, $data, fn () => null, fn () => $posting);

        return $event->id;
    }

    public function balance(Club $club, string $table, string $column, int|string $value): int
    {
        return Money::minor((string) DB::table($table)->where('club_id', $club->id)->where($column, $value)->selectRaw('COALESCE(SUM(amount_minor),0) AS amount')->value('amount'));
    }
}
