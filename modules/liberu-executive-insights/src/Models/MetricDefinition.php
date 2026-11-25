<?php

declare(strict_types=1);

namespace Liberu\ExecutiveInsights\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $team_id
 * @property string $key
 * @property string $name
 * @property string $formula
 * @property string $version
 * @property array<string, mixed> $dimensions
 * @property string $currency
 * @property string $timezone
 * @property int $freshness_seconds
 * @property bool $is_active
 */
final class MetricDefinition extends Model
{
    protected $table = 'liberu_metric_definitions';

    protected $fillable = ['team_id', 'key', 'name', 'formula', 'version', 'dimensions', 'currency', 'timezone', 'freshness_seconds', 'is_active'];

    protected function casts(): array
    {
        return ['dimensions' => 'array', 'freshness_seconds' => 'integer', 'is_active' => 'boolean'];
    }

    public function scopeForTeam(Builder $query, ?int $teamId): Builder
    {
        return $query->where(function (Builder $query) use ($teamId): void {
            $query->whereNull('team_id');
            if ($teamId !== null) {
                $query->orWhere('team_id', $teamId);
            }
        });
    }
}
