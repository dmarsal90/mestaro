<?php

namespace App\Domain;

use RuntimeException;

/** Something chosen exists no longer, is sold out or is outside its hours. */
final class ProductUnavailable extends RuntimeException {}
