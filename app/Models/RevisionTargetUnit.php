<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Daily revision target units — mirrors revision_plans.target_unit.
 * 'page' is arithmetic; the others are whole canonical divisions.
 */
enum RevisionTargetUnit: string
{
    case Page = 'page';
    case Hizb = 'hizb';
    case Rub = 'rub';
    case Juz = 'juz';

    public function isPage(): bool
    {
        return $this === self::Page;
    }

    public function toDivisionType(): ?DivisionType
    {
        return match ($this) {
            self::Page => null,
            self::Hizb => DivisionType::Hizb,
            self::Rub => DivisionType::Rub,
            self::Juz => DivisionType::Juz,
        };
    }
}
