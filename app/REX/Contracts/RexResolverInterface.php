<?php
declare(strict_types=1);

namespace App\REX\Contracts;

use App\REX\DTO\RexReservation;
use App\REX\DTO\RexReservationDescriptor;
use App\REX\DTO\RexResolutionRequest;
use App\REX\DTO\RexResolutionResult;

interface RexResolverInterface
{
    public function resolve(
        RexResolutionRequest $request
    ): RexResolutionResult;

    public function describe(
        RexReservation $reservation
    ): RexReservationDescriptor;

    public function previewDescribe(
        string $resourceType,
        int $resourceId,
        array $context
    ): RexReservationDescriptor;
}