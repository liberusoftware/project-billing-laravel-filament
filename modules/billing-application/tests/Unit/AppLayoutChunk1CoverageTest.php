<?php

declare(strict_types=1);

use App\View\Components\AppLayout;
use Illuminate\View\View;

it('renders the layouts.app view from AppLayout component', function (): void {
    $component = new AppLayout();
    $view = $component->render();

    expect($view)->toBeInstanceOf(View::class)
        ->and($view->name())->toBe('layouts.app');
});
