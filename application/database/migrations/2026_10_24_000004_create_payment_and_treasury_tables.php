<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('club_id');
            $t->unsignedBigInteger('membership_id')->nullable();
            $t->unsignedBigInteger('custodian_membership_id')->nullable();
            $t->enum('method', ['card', 'cash']);
            $t->bigInteger('amount_minor');
            $t->bigInteger('cancelled_minor')->default(0);
            $t->bigInteger('refunded_minor')->default(0);
            $t->date('paid_on');
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('event_id');
            $t->timestamp('created_at', 6);
            $t->timestamp('updated_at', 6);
            $t->unique(['club_id', 'id']);
            $t->unique('event_id');
            foreach (['membership_id', 'custodian_membership_id'] as $col) {
                $t->foreign(['club_id', $col])->references(['club_id', 'id'])->on('memberships')->restrictOnDelete();
            }
            $t->foreign(['club_id', 'event_id'])->references(['club_id', 'id'])->on('financial_events')->restrictOnDelete();
            $t->index(['club_id', 'paid_on']);
        });
        Schema::create('payment_claims', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('club_id');
            $t->unsignedBigInteger('membership_id');
            $t->unsignedBigInteger('custodian_membership_id')->nullable();
            $t->enum('method', ['card', 'cash']);
            $t->bigInteger('amount_minor');
            $t->date('paid_on');
            $t->string('comment', 500)->nullable();
            $t->enum('status', ['pending', 'confirmed', 'rejected'])->default('pending');
            $t->unsignedBigInteger('receipt_id')->nullable()->unique();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->string('review_reason', 500)->nullable();
            $t->timestamp('reviewed_at', 6)->nullable();
            $t->timestamp('created_at', 6);
            $t->unique(['club_id', 'id']);
            foreach (['membership_id', 'custodian_membership_id'] as $col) {
                $t->foreign(['club_id', $col])->references(['club_id', 'id'])->on('memberships')->restrictOnDelete();
            }
            $t->foreign(['club_id', 'receipt_id'])->references(['club_id', 'id'])->on('payment_receipts')->restrictOnDelete();
            $t->foreign('reviewed_by')->references('id')->on('users')->restrictOnDelete();
            $t->index(['club_id', 'status', 'id']);
        });
        Schema::create('expense_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('club_id')->constrained()->restrictOnDelete();
            $t->string('title', 150);
            $t->bigInteger('amount_minor');
            $t->unsignedTinyInteger('day_of_month');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps(6);
            $t->unique(['club_id', 'id']);
        });
        Schema::create('expense_occurrences', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('club_id');
            $t->unsignedBigInteger('template_id');
            $t->date('due_on');
            $t->string('title', 150);
            $t->bigInteger('amount_minor');
            $t->unsignedBigInteger('event_id')->nullable();
            $t->timestamp('created_at', 6);
            $t->unique(['template_id', 'due_on']);
            $t->unique(['club_id', 'id']);
            $t->foreign(['club_id', 'template_id'])->references(['club_id', 'id'])->on('expense_templates')->restrictOnDelete();
            $t->foreign(['club_id', 'event_id'])->references(['club_id', 'id'])->on('financial_events')->restrictOnDelete();
        });
        Schema::create('card_reconciliations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('club_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->bigInteger('actual_minor');
            $t->bigInteger('calculated_minor');
            $t->bigInteger('difference_minor');
            $t->timestamp('as_of', 6);
            $t->timestamp('created_at', 6);
            $t->string('comment', 500);
            $t->unique(['club_id', 'id']);
        });
        Schema::create('payment_outbox', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('club_id');
            $t->unsignedBigInteger('claim_id');
            $t->string('topic', 64);
            $t->json('payload');
            $t->timestamp('created_at', 6);
            $t->unique(['claim_id', 'topic']);
            $t->foreign(['club_id', 'claim_id'])->references(['club_id', 'id'])->on('payment_claims')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['payment_outbox', 'card_reconciliations', 'expense_occurrences', 'expense_templates', 'payment_claims', 'payment_receipts'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
