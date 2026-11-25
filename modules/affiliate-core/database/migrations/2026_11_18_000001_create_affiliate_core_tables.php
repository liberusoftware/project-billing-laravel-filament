<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_core_programs', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('key');
            $table->string('name');
            $table->unsignedInteger('attribution_window_days')->default(30);
            $table->unsignedInteger('hold_days')->default(14);
            $table->unsignedInteger('default_basis_points')->default(1000);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('affiliate_core_conversions', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('source_type');
            $table->string('source_id');
            $table->string('partner_id')->index();
            $table->string('status', 32)->default('held');
            $table->bigInteger('net_amount_minor');
            $table->bigInteger('commission_minor');
            $table->string('currency', 3);
            $table->timestamps();
            $table->unique(['tenant_id', 'source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_core_conversions');
        Schema::dropIfExists('affiliate_core_programs');
    }
};
