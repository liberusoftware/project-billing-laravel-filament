<?php

declare(strict_types=1);

namespace Liberu\Billing\Provisioning\Filament\Resources\ProvisioningOperationResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Liberu\Billing\Provisioning\Filament\Resources\ProvisioningOperationResource;

final class ListProvisioningOperations extends ListRecords
{
    protected static string $resource = ProvisioningOperationResource::class;
}
