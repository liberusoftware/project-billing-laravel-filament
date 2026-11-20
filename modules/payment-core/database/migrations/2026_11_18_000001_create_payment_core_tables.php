<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_core_intents', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('provider_key', 64)->index();
            $table->string('provider_intent_id')->index();
            $table->string('idempotency_key')->unique();
            $table->string('state', 64)->index();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_core_webhook_inbox', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_key', 64);
            $table->string('provider_event_id');
            $table->string('event_type');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider_key', 'provider_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_core_webhook_inbox');
        Schema::dropIfExists('payment_core_intents');
    }
};
