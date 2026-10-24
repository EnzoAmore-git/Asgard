<?php

namespace App\Services\Finance;

use App\Models\Club;
use App\Models\User;
use App\Services\Clubs\ClubAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class OpeningBalances
{
    public function __construct(private Journal $journal, private ClubAccess $access) {}

    public function set(Club $club, User $actor, string $key, array $input): object
    {
        $data = Validator::make($input, [
            'target' => ['required', Rule::in(['member', 'card', 'custody'])],
            'membership_id' => 'nullable|integer|min:1', 'amount_minor' => 'required|string',
            'currency' => 'required|string|size:3', 'date' => 'required|date_format:Y-m-d',
            'reason' => 'required|string|min:2|max:500', 'previous_event_id' => 'present|nullable|integer|min:1',
        ])->validate();
        abort_unless(($data['target'] === 'card') === empty($data['membership_id']), 422, 'Укажите участника для личного остатка или денег у ответственного.');
        $value = Money::minor($data['amount_minor']);
        $data['membership_id'] = isset($data['membership_id']) ? (int) $data['membership_id'] : null;
        $data['previous_event_id'] = isset($data['previous_event_id']) ? (int) $data['previous_event_id'] : null;
        $data['occurred_at'] = Carbon::createFromFormat('!Y-m-d', $data['date'], $club->timezone)->utc()->toISOString();
        $source = $data['target'].':'.($data['membership_id'] ?? 'club').':'.($data['previous_event_id'] ?? 'initial');

        return $this->journal->post($club, $actor, $key, 'opening_balance', $source, $data,
            fn (Club $locked) => $this->access->authorize($actor, $locked, 'finance.manage'),
            function (Club $locked) use ($data, $value) {
                $current = DB::table('financial_events')->where('club_id', $locked->id)->where('type', 'opening_'.$data['target'])
                    ->where('opening_membership_id', $data['membership_id'])->orderByDesc('id')->first();
                abort_unless($current?->id === $data['previous_event_id'], 409, 'Начальные данные уже изменились. Обновите страницу и проверьте сумму.');
                $delta = Money::add($value, -($current ? Money::minor((string) $current->opening_value_minor) : 0));

                return Posting::opening($data['target'], $data['membership_id'], $value, $delta, $current?->id);
            });
    }
}
