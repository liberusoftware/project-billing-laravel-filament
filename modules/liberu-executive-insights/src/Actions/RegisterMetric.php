<?php

declare(strict_types=1);

namespace Liberu\ExecutiveInsights\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Liberu\ExecutiveInsights\Events\MetricRegistered;
use Liberu\ExecutiveInsights\Models\MetricDefinition;

final class RegisterMetric
{
    public function __construct(private readonly Dispatcher $events) {}

    /** @param array{team_id?: int|null, key: string, name: string, formula: string, version: string, dimensions?: array<string, mixed>, currency: string, timezone: string, freshness_seconds: int} $attributes */
    public function handle(array $attributes): MetricDefinition
    {
        if (! preg_match('/^[A-Z]{3}$/', $attributes['currency'])) {
            throw ValidationException::withMessages(['currency' => 'Currency must be an uppercase ISO-4217 code.']);
        }
        if ($attributes['freshness_seconds'] < 1) {
            throw ValidationException::withMessages(['freshness_seconds' => 'Freshness must be positive.']);
        }

        return DB::transaction(function () use ($attributes): MetricDefinition {
            $metric = MetricDefinition::query()->create([
                ...$attributes,
                'dimensions' => $attributes['dimensions'] ?? [],
                'is_active' => true,
            ]);
            $this->events->dispatch(new MetricRegistered($metric));

            return $metric;
        });
    }
}
