<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('passkey_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('authenticatable_type');
            $table->unsignedBigInteger('authenticatable_id');
            $table->string('credential_id')->unique();
            $table->text('public_key_cose');
            $table->string('name');
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->string('aaguid', 64)->nullable();
            $table->json('transports')->nullable();
            $table->boolean('backup_eligible')->default(false);
            $table->boolean('backup_state')->default(false);
            $table->timestamp('quarantined_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index(['authenticatable_type', 'authenticatable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkey_credentials');
    }
};
