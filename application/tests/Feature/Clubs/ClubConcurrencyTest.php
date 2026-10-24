<?php

namespace Tests\Feature\Clubs;

use App\Models\Club;
use App\Models\Membership;
use App\Models\User;
use App\Services\Clubs\ClubService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ClubConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_concurrent_transfers_cannot_remove_or_replace_the_new_owner(): void
    {
        $operator = User::factory()->create(['is_operator' => true]);
        $owner = User::factory()->create();
        $club = app(ClubService::class)->create($operator, $owner, ['name' => 'Гонки', 'owner_name' => 'Рагнар', 'timezone' => 'Europe/Moscow', 'currency' => 'RUB', 'period_start_day' => 10]);
        $invite = app(ClubService::class)->invite($owner, $club);
        $token = basename($invite['url']);
        $one = app(ClubService::class)->join(User::factory()->create(), $token, 'Бьорн');
        $two = app(ClubService::class)->join(User::factory()->create(), $token, 'Тор');
        $clubId = $club->id;
        $actorId = $owner->id;
        $targetOne = $one->id;
        $targetTwo = $two->id;
        $results = Concurrency::driver('process')->run([
            static function () use ($clubId, $actorId, $targetOne) {
                try {
                    app(ClubService::class)->transfer(User::findOrFail($actorId), Club::findOrFail($clubId), $targetOne);

                    return 204;
                } catch (HttpException $error) {
                    return $error->getStatusCode();
                }
            },
            static function () use ($clubId, $actorId, $targetTwo) {
                try {
                    app(ClubService::class)->transfer(User::findOrFail($actorId), Club::findOrFail($clubId), $targetTwo);

                    return 204;
                } catch (HttpException $error) {
                    return $error->getStatusCode();
                }
            },
        ]);
        sort($results);
        $this->assertSame([204, 403], $results);
        $this->assertContains((int) $club->fresh()->owner_membership_id, [$targetOne, $targetTwo]);
        $this->assertSame(1, DB::table('audit_events')->where('event', 'ownership.transferred')->count());
    }

    public function test_concurrent_acceptance_of_same_shared_link_creates_one_membership(): void
    {
        $operator = User::factory()->create(['is_operator' => true]);
        $owner = User::factory()->create();
        $joiner = User::factory()->create();
        $club = app(ClubService::class)->create($operator, $owner, ['name' => 'Гонки', 'owner_name' => 'Рагнар', 'timezone' => 'Europe/Moscow', 'currency' => 'RUB', 'period_start_day' => 10]);
        $token = basename(app(ClubService::class)->invite($owner, $club)['url']);
        $userId = $joiner->id;
        $results = Concurrency::driver('process')->run([
            static fn () => app(ClubService::class)->join(User::findOrFail($userId), $token, 'Бьорн')->id,
            static fn () => app(ClubService::class)->join(User::findOrFail($userId), $token, 'Бьорн')->id,
        ]);
        $this->assertSame($results[0], $results[1]);
        $this->assertSame(1, Membership::where('club_id',$club->id)->where('user_id',$joiner->id)->count());
    }
}
