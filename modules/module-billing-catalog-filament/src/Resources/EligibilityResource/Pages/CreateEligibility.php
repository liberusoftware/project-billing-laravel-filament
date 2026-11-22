<?php

declare(strict_types=1);

namespace Liberu\Billing\Catalog\Filament\Resources\EligibilityResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Liberu\Billing\Catalog\Actions\CreateCatalogRecord;
use Liberu\Billing\Catalog\Filament\Resources\EligibilityResource;
use Liberu\Billing\Catalog\Models\CatalogRecord;
use Liberu\Billing\Catalog\Models\Eligibility;

final class CreateEligibility extends CreateRecord
{
    protected static string $resource = EligibilityResource::class;

    protected function handleRecordCreation(array $data): CatalogRecord
    {
        $data['team_id'] = data_get(auth()->user(), 'current_team_id') ?? data_get(auth()->user(), 'currentTeam.id');

        return app(CreateCatalogRecord::class)->execute(Eligibility::class, $data);
    }
}
