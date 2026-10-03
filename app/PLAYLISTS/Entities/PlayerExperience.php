<?php
declare(strict_types=1);

namespace App\PLAYLISTS\Entities;

final class PlayerExperience
{
    public function __construct(
        public readonly int $playerExperienceId,
        public readonly string $experienceKey,
        public readonly string $name,
        public readonly string $slideFlag,
        public readonly string $paletteViewerKey,
        public readonly string $rexParentExperienceKey,
        public readonly int $ctaPageId,
        public readonly bool $isActive,
    ) {}
}
