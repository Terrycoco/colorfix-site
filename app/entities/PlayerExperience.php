<?php
declare(strict_types=1);

namespace App\Entities;

final class PlayerExperience
{
    public function __construct(
        public int $playerExperienceId,
        public string $experienceKey,
        public string $name,
        public string $slideFlag,
        public string $paletteViewerKey,
        public string $rexParentExperienceKey,
        public int $ctaPageId,
        public bool $isActive
    ) {}
}
