<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('liberu_workflow_runs')) {
            return;
        }

        Schema::create('liberu_workflow_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('correlation_id')->unique();
            $table->string('workflow_key');
            $table->string('contract_version');
            $table->string('state')->default('pending');
            $table->json('steps')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liberu_workflow_runs');
    }
};
