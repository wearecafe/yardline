<?php

namespace App\Imports;

use RuntimeException;

/** A row failed, so the import is rolled back. */
final class ImportFailed extends RuntimeException {}
