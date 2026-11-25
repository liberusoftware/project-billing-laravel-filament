<?php

declare(strict_types=1);

namespace Liberu\Billing\Provisioning\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait ScopesCurrentTeam
{
    // These module models belong to the current team through team_id.
    protected static bool $isScopedToTenant = false;

    public static function getEloquentQuery(): Builder
    {
        $team = data_get(auth()->user(), 'current_team_id') ?? data_get(auth()->user(), 'currentTeam.id');

        return parent::getEloquentQuery()->where('team_id', $team);
    }
}
