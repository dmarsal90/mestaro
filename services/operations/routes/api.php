<?php

// Business endpoints arrive in a later phase. Tenant routes must sit behind
// [Mestaro\Kernel\Auth\AuthenticateJwt::class, Mestaro\Kernel\Tenancy\ResolveTenant::class].
