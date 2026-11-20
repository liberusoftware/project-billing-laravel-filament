<?php

declare(strict_types=1);

namespace Liberu\PlatformOrchestration\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Liberu\PlatformOrchestration\Enums\CompositionState;

/**
 * @property int $id
 * @property int|null $team_id
 * @property string $key
 * @property string $display_name
 * @property string $application
 * @property CompositionState $state
 * @property array<string, mixed> $manifest
 * @property array<string, mixed>|null $metadata
 */
final class PlatformComposition extends Model
{
    protected $table = 'liberu_platform_compositions';

    protected $fillable = ['team_id', 'key', 'display_name', 'application', 'state', 'manifest', 'metadata'];

    protected function casts(): array
    {
        return ['state' => CompositionState::class, 'manifest' => 'array', 'metadata' => 'array'];
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

    public function canTransitionTo(CompositionState $state): bool
    {
        return in_array($state, self::allowedTransitions()[$this->state->value] ?? [], true);
    }

    /** @return array<string, list<CompositionState>> */
    private static function allowedTransitions(): array
    {
        return [
            'draft' => [CompositionState::Installed, CompositionState::Failed],
            'installed' => [CompositionState::Enabled, CompositionState::Disabled, CompositionState::Failed],
            'enabled' => [CompositionState::Entitled, CompositionState::Disabled, CompositionState::Failed],
            'entitled' => [CompositionState::Active, CompositionState::Disabled, CompositionState::Failed],
            'active' => [CompositionState::Disabled, CompositionState::Failed],
            'disabled' => [CompositionState::Enabled, CompositionState::Failed],
            'failed' => [CompositionState::Draft, CompositionState::Installed],
        ];
    }
}
