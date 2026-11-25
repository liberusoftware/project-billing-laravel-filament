<?php

declare(strict_types=1);

namespace Liberu\PlatformOrchestration\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Liberu\PlatformOrchestration\Enums\CompositionState;
use Liberu\PlatformOrchestration\Events\CompositionCreated;
use Liberu\PlatformOrchestration\Models\PlatformComposition;

final class CreateComposition
{
    public function __construct(private readonly Dispatcher $events) {}

    /** @param array{team_id?: int|null, key: string, display_name: string, application: string, manifest: array<string, mixed>, metadata?: array<string, mixed>} $attributes */
    public function handle(array $attributes): PlatformComposition
    {
        $this->validateManifest($attributes['manifest']);

        return DB::transaction(function () use ($attributes): PlatformComposition {
            $composition = PlatformComposition::query()->create([
                ...$attributes,
                'state' => CompositionState::Draft,
                'metadata' => $attributes['metadata'] ?? [],
            ]);

            $this->events->dispatch(new CompositionCreated($composition));

            return $composition;
        });
    }

    /** @param array<string, mixed> $manifest */
    private function validateManifest(array $manifest): void
    {
        $applications = Arr::get($manifest, 'applications');
        $modules = Arr::get($manifest, 'modules');

        if (! is_array($applications) || $applications === [] || ! is_array($modules)) {
            throw ValidationException::withMessages(['manifest' => 'A composition manifest requires applications and modules.']);
        }

        foreach ([$applications, $modules] as $items) {
            foreach ($items as $item) {
                if (! is_string($item) || trim($item) === '') {
                    throw ValidationException::withMessages(['manifest' => 'Manifest entries must be non-empty strings.']);
                }
            }
        }
    }
}
