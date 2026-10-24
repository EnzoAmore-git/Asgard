<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->boolean('is_operator')->default(false);
        });
        Schema::create('identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('provider', 16);
            $table->string('subject', 191)->collation('utf8mb4_bin');
            $table->timestamps();
            $table->unique(['provider', 'subject']);
        });
        Schema::create('auth_flows', function (Blueprint $table) {
            $table->id();
            $table->char('state_hash', 64)->unique();
            $table->char('session_hash', 64);
            $table->string('provider', 16);
            $table->string('purpose', 8);
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('verifier');
            $table->char('nonce_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
        Schema::create('clubs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('timezone', 64);
            $table->char('currency', 3);
            $table->unsignedTinyInteger('period_start_day');
            $table->unsignedBigInteger('owner_membership_id')->nullable();
            $table->timestamps();
        });
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('club_name', 80);
            $table->string('name_key', 80)->collation('utf8mb4_bin');
            $table->string('status', 16)->default('active');
            $table->timestamp('frozen_until')->nullable();
            $table->timestamps();
            $table->unique(['club_id', 'user_id']);
            $table->unique(['club_id', 'name_key']);
            $table->unique(['club_id', 'id']);
        });
        Schema::table('clubs', function (Blueprint $table) {
            $table->foreign(['id', 'owner_membership_id'])->references(['club_id', 'id'])->on('memberships')->restrictOnDelete();
        });
        Schema::create('permission_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('club_id');
            $table->unsignedBigInteger('membership_id');
            $table->string('permission', 48);
            $table->timestamps();
            $table->foreign(['club_id', 'membership_id'])->references(['club_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->unique(['membership_id', 'permission']);
        });
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('membership_status_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('club_id');
            $table->unsignedBigInteger('membership_id');
            $table->foreign(['club_id', 'membership_id'])->references(['club_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('from_status', 16);
            $table->string('to_status', 16);
            $table->timestamp('frozen_until')->nullable();
            $table->text('reason');
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('event', 64);
            $table->json('details');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('membership_status_history');
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('permission_grants');
        Schema::table('clubs', fn (Blueprint $table) => $table->dropForeign(['id', 'owner_membership_id']));
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('clubs');
        Schema::dropIfExists('auth_flows');
        Schema::dropIfExists('identities');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_operator'));
    }
};
