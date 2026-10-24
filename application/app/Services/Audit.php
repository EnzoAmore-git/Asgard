<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class Audit
{
    public static function record(int $actor, string $event, array $details, ?int $club = null): void
    {
        DB::table('audit_events')->insert([
            'actor_id' => $actor, 'club_id' => $club, 'event' => $event,
            'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
