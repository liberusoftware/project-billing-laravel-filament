<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('liberu_platform_compositions')) {
            return;
        }

        Schema::create('liberu_platform_compositions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('key')->index();
            $table->string('display_name');
            $table->string('application');
            $table->string('state')->default('draft');
            $table->json('manifest');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liberu_platform_compositions');
    }
};
