<?php
declare(strict_types=1);
namespace App\REX\Exceptions;

/** A confirmed missing or intentionally unavailable REX resource. */
final class RexNotFoundException extends \RuntimeException {}
