<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attachments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('club_id');
            $t->unsignedBigInteger('claim_id')->unique();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('path', 191)->unique();
            $t->string('extension', 5);
            $t->string('mime', 40);
            $t->unsignedInteger('size');
            $t->char('sha256', 64);
            $t->timestamp('created_at', 6);
            $t->foreign(['club_id', 'claim_id'])->references(['club_id', 'id'])->on('payment_claims')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attachments');
    }
};
