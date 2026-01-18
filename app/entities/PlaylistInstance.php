<?php
declare(strict_types=1);

namespace App\Entities;

final class PlaylistInstance
{
    public function __construct(
        public ?int $id,
        public int $playlistId,
        public string $instanceName,
        public ?string $displayTitle,
        public ?string $instanceNotes,
        public string $introLayout,
        public ?string $introTitle,
        public ?string $introSubtitle,
        public ?string $introBody,
        public ?string $introImageUrl,
        public ?int $ctaGroupId,
        public ?string $ctaContextKey,
        public ?string $ctaOverrides,
        public bool $shareEnabled,
        public ?string $shareTitle,
        public ?string $shareDescription,
        public ?string $shareImageUrl,
        public bool $skipIntroOnReplay,
        public bool $hideStars,
        public bool $isActive,
        public ?int $createdFromInstance
    ) {}
}
