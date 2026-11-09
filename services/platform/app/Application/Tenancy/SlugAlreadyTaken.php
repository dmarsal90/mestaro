<?php

declare(strict_types=1);

namespace App\Application\Tenancy;

use RuntimeException;

final class SlugAlreadyTaken extends RuntimeException {}
