<?php

declare(strict_types=1);

use App\Models\Invoice_Item;
use App\Models\PackageGroup;
use App\Models\Products_Service;
use App\Models\Subscription;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

it('resolves the package group team relationship', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $group = PackageGroup::create([
        'team_id' => $user->currentTeam->id,
        'name' => 'Hosting Packages',
        'is_active' => true,
    ]);

    expect($group->team())->toBeInstanceOf(BelongsTo::class)
        ->and($group->team->id)->toBe($user->currentTeam->id);
});

it('defines invoice items and usage records relations and handles usage metrics', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $fixedProduct = Products_Service::factory()->create([
        'team_id' => $user->currentTeam->id,
        'pricing_model' => 'fixed',
    ]);

    $invoiceItemsRelation = $fixedProduct->invoiceItems();
    $usageRecordsRelation = $fixedProduct->usageRecords();

    expect($invoiceItemsRelation)->toBeInstanceOf(HasMany::class)
        ->and($invoiceItemsRelation->getForeignKeyName())->toBe('product_service_id')
        ->and($invoiceItemsRelation->getRelated())->toBeInstanceOf(Invoice_Item::class)
        ->and($usageRecordsRelation)->toBeInstanceOf(HasManyThrough::class)
        ->and($usageRecordsRelation->getRelated())->toBeInstanceOf(UsageRecord::class)
        ->and($usageRecordsRelation->getParent())->toBeInstanceOf(Subscription::class)
        ->and($fixedProduct->getUsageMetrics())->toBe([]);

    $usageProduct = Products_Service::factory()->create([
        'team_id' => $user->currentTeam->id,
        'pricing_model' => 'usage_based',
        'custom_pricing_data' => [
            'usage_config' => [
                'bandwidth_gb' => ['rate' => 0.10],
            ],
        ],
    ]);

    expect($usageProduct->getUsageMetrics())->toBe(['bandwidth_gb']);

    $subscription = Subscription::factory()->create([
        'product_service_id' => $usageProduct->id,
    ]);

    $record = $usageProduct->recordUsage($subscription->id, 'bandwidth_gb', 42.5);

    expect($record)->toBeInstanceOf(UsageRecord::class)
        ->and($record->subscription_id)->toBe($subscription->id)
        ->and($record->metric_name)->toBe('bandwidth_gb')
        ->and((float) $record->quantity)->toBe(42.5);

    $this->assertDatabaseHas('usage_records', [
        'id' => $record->id,
        'subscription_id' => $subscription->id,
        'metric_name' => 'bandwidth_gb',
    ]);

    expect(fn () => $usageProduct->recordUsage($subscription->id, 'unknown_metric', 10))
        ->toThrow(Exception::class, 'Invalid usage metric: unknown_metric');
});
