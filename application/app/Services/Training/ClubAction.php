<?php

namespace App\Services\Training;

use App\Models\Club;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ClubAction
{
    public function run(Club $club, User $actor, string $key, string $action, array $data, Closure $authorize, Closure $work): array
    {
        Validator::make(['key' => $key], ['key' => 'required|string|min:16|max:128'])->validate();
        $hash = hash('sha256', json_encode($this->sort(['action' => $action, 'actor' => $actor->id, 'data' => $data]), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($club, $key, $hash, $authorize, $work) {
            $locked = Club::lockForUpdate()->findOrFail($club->id);
            $authorize($locked);
            $query = DB::table('club_actions')->where('club_id', $club->id)->where('key_hash', hash('sha256', $key));
            if ($old = $query->first()) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Запрос уже использован с другими данными. Обновите страницу.');

                return json_decode($old->result, true, 512, JSON_THROW_ON_ERROR);
            }
            $result = $work($locked);
            DB::table('club_actions')->insert(['club_id' => $club->id, 'key_hash' => hash('sha256', $key), 'request_hash' => $hash, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $result;
        }, 3);
    }

    private function sort(array $data): array
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->sort($value);
            }
        }

        return $data;
    }
}
