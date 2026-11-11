<?php

declare(strict_types=1);

namespace Company\AuditClient\Enums;

enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
