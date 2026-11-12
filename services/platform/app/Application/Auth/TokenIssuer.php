<?php

namespace App\Application\Auth;

use Mestaro\Kernel\Auth\AccessClaims;

interface TokenIssuer
{
    public function issue(AccessClaims $claims): string;
}
