<?php

declare(strict_types=1);

namespace App\Enum;

// There is deliberately no "safe" state: Sans only reports what it detected and what it could not determine.
enum Verdict: string
{
    case Detected = 'detected';
    case DependsOnProduct = 'depends';
    case Incomplete = 'incomplete';
    case NotDetected = 'not-detected';
}
