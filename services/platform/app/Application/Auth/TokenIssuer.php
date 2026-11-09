<?php

namespace App\Application\Auth;

interface TokenIssuer
{
    public function issue(AccessClaims $claims): string;
}
