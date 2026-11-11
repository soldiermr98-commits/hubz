<?php
declare(strict_types=1);

namespace App\Core;

/** A reviewed accounting conflict, without exposing any receipt details. */
final class FinanceMigrationConflict extends \RuntimeException {}
