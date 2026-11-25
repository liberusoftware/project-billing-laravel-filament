<?php

declare(strict_types=1);

use App\Enums\ProjectStatus;
use App\Enums\TaskPriority;

it('returns expected labels and colors for every ProjectStatus case', function (): void {
    expect(ProjectStatus::Open->getLabel())->toBe('Open')
        ->and(ProjectStatus::InProgress->getLabel())->toBe('In Progress')
        ->and(ProjectStatus::OnHold->getLabel())->toBe('On Hold')
        ->and(ProjectStatus::Completed->getLabel())->toBe('Completed')
        ->and(ProjectStatus::Cancelled->getLabel())->toBe('Cancelled')
        ->and(ProjectStatus::Open->getColor())->toBe('info')
        ->and(ProjectStatus::InProgress->getColor())->toBe('warning')
        ->and(ProjectStatus::OnHold->getColor())->toBe('gray')
        ->and(ProjectStatus::Completed->getColor())->toBe('success')
        ->and(ProjectStatus::Cancelled->getColor())->toBe('danger');
});

it('returns expected labels and colors for every TaskPriority case', function (): void {
    expect(TaskPriority::Low->getLabel())->toBe('Low')
        ->and(TaskPriority::Medium->getLabel())->toBe('Medium')
        ->and(TaskPriority::High->getLabel())->toBe('High')
        ->and(TaskPriority::Low->getColor())->toBe('gray')
        ->and(TaskPriority::Medium->getColor())->toBe('info')
        ->and(TaskPriority::High->getColor())->toBe('danger');
});
