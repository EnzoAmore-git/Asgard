<?php

namespace App\Services\Auth;

use App\Models\Identity;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class IdentityResolver
{
    public function resolve(string $provider, array $verified, ?User $linkTo): User
    {
        try {
            return DB::transaction(function () use ($provider, $verified, $linkTo) {
                if ($linkTo) {
                    $linkTo = User::lockForUpdate()->findOrFail($linkTo->id);
                }
                $identity = Identity::where('provider', $provider)->where('subject', $verified['subject'])->lockForUpdate()->first();
                if ($identity) {
                    abort_if($linkTo && $identity->user_id !== $linkTo->id, 409, 'Этот способ входа уже связан с другим аккаунтом.');

                    return User::findOrFail($identity->user_id);
                }
                $user = $linkTo ?? User::create(['name' => $verified['name']]);
                Identity::create(['user_id' => $user->id, 'provider' => $provider, 'subject' => $verified['subject']]);
                Audit::record($user->id, $linkTo ? 'identity.linked' : 'account.created', ['provider' => $provider]);

                return $user;
            }, 3);
        } catch (QueryException $error) {
            // A concurrent first login may have created this exact identity.
            if (($error->errorInfo[1] ?? null) !== 1062) {
                throw $error;
            }
            $identity = Identity::where('provider', $provider)->where('subject', $verified['subject'])->firstOrFail();
            abort_if($linkTo && $identity->user_id !== $linkTo->id, 409, 'Этот способ входа уже связан с другим аккаунтом.');

            return User::findOrFail($identity->user_id);
        }
    }
}
