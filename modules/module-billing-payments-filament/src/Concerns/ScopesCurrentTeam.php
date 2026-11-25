<?php

declare(strict_types=1);

namespace Liberu\Billing\Payments\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait ScopesCurrentTeam
{
    // These module models belong to the current team through team_id or their payment.
    protected static bool $isScopedToTenant = false;

    public static function getEloquentQuery(): Builder
    {
        $team = data_get(auth()->user(), 'current_team_id') ?? data_get(auth()->user(), 'currentTeam.id');
        $query = parent::getEloquentQuery();
        $scope = static function (Builder $builder) use ($team): void {
            if ($team === null) {
                $builder->whereNull('team_id');
            } else {
                $builder->whereNull('team_id')->orWhere('team_id', (int) $team);
            }
        };

        if (method_exists($query->getModel(), 'payment')) {
            return $query->whereHas('payment', $scope);
        }

        return $query->where($scope);
    }
}
