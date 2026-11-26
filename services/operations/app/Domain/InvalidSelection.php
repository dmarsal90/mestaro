<?php

namespace App\Domain;

use RuntimeException;

/** A choice that the product never allowed. */
final class InvalidSelection extends RuntimeException {}
