<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_actions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('club_id')->constrained()->restrictOnDelete();
            $t->char('key_hash', 64);
            $t->char('request_hash', 64);
            $t->json('result');
            $t->timestamp('created_at');
            $t->unique(['club_id', 'key_hash']);
        });
        Schema::create('billing_settings', function (Blueprint $t) {
            $t->foreignId('club_id')->primary()->constrained()->restrictOnDelete();
            $t->date('active_from');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
        });
        Schema::create('tariff_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('club_id')->constrained()->restrictOnDelete();
            $t->date('effective_from');
            $t->bigInteger('amount_minor');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->timestamp('created_at');
            $t->index(['club_id', 'effective_from', 'id']);
            $t->unique(['club_id', 'id']);
        });
        Schema::create('exemption_versions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('club_id');
            $t->unsignedBigInteger('membership_id');
            $t->date('effective_from');
            $t->boolean('exempt');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->timestamp('created_at');
            $t->foreign(['club_id', 'membership_id'])->references(['club_id', 'id'])->on('memberships')->restrictOnDelete();
            $t->index(['club_id', 'membership_id', 'effective_from', 'id']);
        });
        Schema::create('billing_periods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('club_id')->constrained()->restrictOnDelete();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('timezone', 64);
            $t->unsignedTinyInteger('nominal_day');
            $t->unique(['club_id', 'starts_on']);
            $t->unique(['club_id', 'id']);
        });
        Schema::create('training_templates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('club_id');
            $t->string('title', 120);
            $t->json('weekdays');
            $t->string('local_time', 5);
            $t->unsignedBigInteger('responsible_membership_id');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->foreign(['club_id', 'responsible_membership_id'])->references(['club_id', 'id'])->on('memberships')->restrictOnDelete();
            $t->unique(['club_id', 'id']);
        });
        Schema::create('trainings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('club_id');
            $t->unsignedBigInteger('template_id')->nullable();
            $t->date('occurrence_date')->nullable();
            $t->string('title', 120);
            $t->timestamp('starts_at');
            $t->unsignedBigInteger('responsible_membership_id');
            $t->enum('status', ['planned', 'closed', 'cancelled'])->default('planned');
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('period_id')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->foreign(['club_id', 'responsible_membership_id'])->references(['club_id', 'id'])->on('memberships')->restrictOnDelete();
            $t->foreign(['club_id', 'template_id'])->references(['club_id', 'id'])->on('training_templates')->restrictOnDelete();
            $t->foreign(['club_id', 'period_id'])->references(['club_id', 'id'])->on('billing_periods')->restrictOnDelete();
            $t->unique(['club_id', 'id']);
            $t->unique(['template_id', 'occurrence_date']);
            $t->index(['club_id', 'starts_at', 'id']);
        });
        foreach (['training_rsvps', 'training_attendances'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->id();
                $t->unsignedBigInteger('club_id');
                $t->unsignedBigInteger('training_id');
                $t->unsignedBigInteger('membership_id');
                if ($table === 'training_rsvps') {
                    $t->enum('response', ['yes', 'no']);
                } else {
                    $t->boolean('draft_present')->default(false);
                    $t->boolean('confirmed_present')->default(false);
                    $t->timestamp('confirmed_at')->nullable();
                }
                $t->timestamps();
                $t->unique(['training_id', 'membership_id']);
                $t->foreign(['club_id', 'training_id'])->references(['club_id', 'id'])->on('trainings')->restrictOnDelete();
                $t->foreign(['club_id', 'membership_id'])->references(['club_id', 'id'])->on('memberships')->restrictOnDelete();
            });
        }
        Schema::create('attendance_revisions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('club_id');
            $t->unsignedBigInteger('training_id');
            $t->unsignedInteger('training_version');
            $t->json('present_members');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('kind', 16);
            $t->text('reason');
            $t->timestamp('created_at');
            $t->foreign(['club_id', 'training_id'])->references(['club_id', 'id'])->on('trainings')->restrictOnDelete();
            $t->unique(['training_id', 'training_version']);
        });
        Schema::create('subscription_obligations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('club_id');
            $t->unsignedBigInteger('period_id');
            $t->unsignedBigInteger('membership_id');
            $t->unsignedBigInteger('tariff_version_id');
            $t->bigInteger('tariff_minor');
            $t->boolean('exempt');
            $t->foreign(['club_id', 'tariff_version_id'])->references(['club_id', 'id'])->on('tariff_versions')->restrictOnDelete();
            $t->bigInteger('current_amount_minor')->default(0);
            $t->unsignedInteger('revision')->default(0);
            $t->unsignedBigInteger('charge_event_id')->nullable();
            $t->timestamps();
            $t->foreign(['club_id', 'period_id'])->references(['club_id', 'id'])->on('billing_periods')->restrictOnDelete();
            $t->foreign(['club_id', 'membership_id'])->references(['club_id', 'id'])->on('memberships')->restrictOnDelete();
            $t->foreign(['club_id', 'charge_event_id'])->references(['club_id', 'id'])->on('financial_events')->restrictOnDelete();
            $t->unique(['club_id', 'membership_id', 'period_id']);
        });
        Schema::create('training_outbox', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('club_id');
            $t->unsignedBigInteger('training_id');
            $t->unsignedInteger('training_version');
            $t->string('topic', 64);
            $t->json('payload');
            $t->timestamp('created_at');
            $t->unique(['training_id', 'training_version', 'topic']);
            $t->foreign(['club_id', 'training_id'])->references(['club_id', 'id'])->on('trainings')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['training_outbox', 'subscription_obligations', 'attendance_revisions', 'training_attendances', 'training_rsvps', 'trainings', 'training_templates', 'billing_periods', 'exemption_versions', 'tariff_versions', 'billing_settings', 'club_actions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
