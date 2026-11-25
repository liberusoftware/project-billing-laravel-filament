<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\OrganisationType;
use App\Models\Subscription;
use App\Models\Team;
use App\Services\ResellerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ResellerServiceChunk21CoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_agreement_rejects_reseller_that_is_not_a_child_of_provider(): void
    {
        $provider = Team::factory()->create(['organisation_type' => OrganisationType::Company]);
        $unrelatedReseller = Team::factory()->create([
            'parent_team_id' => null,
            'organisation_type' => OrganisationType::Reseller,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The reseller organisation must be a child of the provider.');

        app(ResellerService::class)->createAgreement($provider, $unrelatedReseller, []);
    }

    public function test_create_agreement_rejects_disallowed_child_organisation_type(): void
    {
        $provider = Team::factory()->create(['organisation_type' => OrganisationType::Company]);
        $subsidiary = Team::factory()->create([
            'parent_team_id' => $provider->id,
            'organisation_type' => OrganisationType::Subsidiary,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The child organisation type cannot hold a reseller agreement.');

        app(ResellerService::class)->createAgreement($provider, $subsidiary, []);
    }

    public function test_delegate_rejects_inactive_reseller_agreement(): void
    {
        $provider = Team::factory()->create(['organisation_type' => OrganisationType::Company]);
        $reseller = Team::factory()->create([
            'parent_team_id' => $provider->id,
            'organisation_type' => OrganisationType::Reseller,
        ]);

        $agreement = app(ResellerService::class)->createAgreement($provider, $reseller, [
            'status' => 'suspended',
        ]);

        $subscription = Subscription::factory()->create([
            'team_id' => $provider->id,
            'price' => 50,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The reseller agreement is not active.');

        app(ResellerService::class)->delegate($agreement, $subscription);
    }

    public function test_delegate_rejects_subscription_not_owned_by_provider(): void
    {
        $provider = Team::factory()->create(['organisation_type' => OrganisationType::Company]);
        $reseller = Team::factory()->create([
            'parent_team_id' => $provider->id,
            'organisation_type' => OrganisationType::Reseller,
        ]);
        $otherTeam = Team::factory()->create(['organisation_type' => OrganisationType::Company]);

        $agreement = app(ResellerService::class)->createAgreement($provider, $reseller, []);

        $subscription = Subscription::factory()->create([
            'team_id' => $otherTeam->id,
            'price' => 50,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only provider-owned services can be delegated.');

        app(ResellerService::class)->delegate($agreement, $subscription);
    }
}
