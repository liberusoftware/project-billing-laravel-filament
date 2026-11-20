<?php

declare(strict_types=1);

namespace Liberu\PlatformOrchestration\Enums;

enum CompositionState: string
{
    case Draft = 'draft';
    case Installed = 'installed';
    case Enabled = 'enabled';
    case Entitled = 'entitled';
    case Active = 'active';
    case Disabled = 'disabled';
    case Failed = 'failed';
}
