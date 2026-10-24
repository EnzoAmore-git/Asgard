<?php

use App\Services\Finance\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->unsignedTinyInteger('currency_scale')->default(2);
        });
        foreach (DB::table('clubs')->get(['id', 'currency']) as $club) {
            DB::table('clubs')->where('id', $club->id)->update(['currency_scale' => Money::scale($club->currency)]);
        }
        Schema::create('financial_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->string('source_type', 48);
            $table->string('source_id', 191)->collation('utf8mb4_bin');
            $table->char('request_hash', 64);
            $table->char('currency', 3);
            $table->unsignedTinyInteger('currency_scale');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('related_event_id')->nullable();
            $table->unsignedBigInteger('opening_membership_id')->nullable();
            $table->bigInteger('opening_value_minor')->nullable();
            $table->timestamp('occurred_at', 6);
            $table->timestamp('created_at', 6);
            $table->text('reason');
            $table->unique(['club_id', 'id']);
            $table->unique(['club_id', 'source_type', 'source_id'], 'financial_event_source_unique');
            $table->foreign(['club_id', 'related_event_id'])->references(['club_id', 'id'])->on('financial_events')->restrictOnDelete();
            $table->foreign(['club_id', 'opening_membership_id'])->references(['club_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->index(['club_id', 'occurred_at', 'id']);
        });
        Schema::create('member_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('club_id');
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('membership_id');
            $table->bigInteger('amount_minor');
            $table->foreign(['club_id', 'event_id'])->references(['club_id', 'id'])->on('financial_events')->restrictOnDelete();
            $table->foreign(['club_id', 'membership_id'])->references(['club_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->unique(['event_id', 'membership_id']);
            $table->index(['club_id', 'membership_id', 'event_id']);
        });
        Schema::create('money_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('club_id');
            $table->unsignedBigInteger('event_id');
            $table->enum('location', ['card', 'custody']);
            $table->unsignedBigInteger('custodian_membership_id')->nullable();
            $table->string('account_key', 64)->storedAs("CASE WHEN location = 'card' THEN 'card' ELSE CONCAT('custody:', custodian_membership_id) END");
            $table->bigInteger('amount_minor');
            $table->foreign(['club_id', 'event_id'])->references(['club_id', 'id'])->on('financial_events')->restrictOnDelete();
            $table->foreign(['club_id', 'custodian_membership_id'])->references(['club_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->unique(['event_id', 'account_key']);
            $table->index(['club_id', 'account_key', 'event_id']);
        });
        DB::statement("ALTER TABLE money_entries ADD CONSTRAINT money_location_valid CHECK ((location = 'card' AND custodian_membership_id IS NULL) OR (location = 'custody' AND custodian_membership_id IS NOT NULL))");
        Schema::create('financial_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('club_id');
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->unsignedBigInteger('event_id');
            $table->timestamp('created_at', 6);
            $table->unique(['club_id', 'key_hash']);
            $table->foreign(['club_id', 'event_id'])->references(['club_id', 'id'])->on('financial_events')->restrictOnDelete();
        });
        Schema::create('financial_outbox', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('club_id');
            $table->unsignedBigInteger('event_id');
            $table->string('topic', 64);
            $table->json('payload');
            $table->timestamp('created_at', 6);
            $table->unique(['event_id', 'topic']);
            $table->foreign(['club_id', 'event_id'])->references(['club_id', 'id'])->on('financial_events')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['financial_outbox', 'financial_requests', 'money_entries', 'member_entries', 'financial_events'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('clubs', fn (Blueprint $table) => $table->dropColumn('currency_scale'));
    }
};
