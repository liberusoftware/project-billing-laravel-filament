<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('liberu_metric_definitions')) {
            return;
        }

        Schema::create('liberu_metric_definitions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('key')->index();
            $table->string('name');
            $table->text('formula');
            $table->string('version');
            $table->json('dimensions')->nullable();
            $table->string('currency', 3);
            $table->string('timezone');
            $table->unsignedInteger('freshness_seconds');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liberu_metric_definitions');
    }
};
