<?php

namespace App\Http\Middleware;

use App\Application\TableSessions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mestaro\Kernel\Http\ApiError;
use Mestaro\Kernel\Tenancy\PostgresTenantDatabaseCreator;
use Mestaro\Kernel\Tenancy\TenantContext;
use Mestaro\Kernel\Tenancy\TenantCredentials;
use Symfony\Component\HttpFoundation\Response;

/**
 * A diner's credential, `<restaurant>.<secret>`: it works only if that restaurant's database holds the secret's hash,
 * and only while its session is live. Exposes `participant` and `table_session` as request attributes and runs the
 * request in that database.
 */
final readonly class AuthenticateParticipant
{
    public function __construct(private TenantContext $context, private TenantCredentials $credentials) {}

    public function handle(Request $request, Closure $next): Response
    {
        $denied = ApiError::response('UNAUTHENTICATED', 'Invalid credentials.', 401);
        [$tenant, $secret] = [...explode('.', (string) $request->bearerToken(), 2), ''];
        try {
            PostgresTenantDatabaseCreator::nameFor($tenant, 'operations');
        } catch (InvalidArgumentException) {
            return $denied;
        }
        $password = $this->credentials->existingPasswordFor($tenant, 'operations');
        if ($password === null) {
            return $denied;
        }

        $this->context->use($tenant, $password);
        try {
            $participant = DB::table('table_session_participants')->where('token_hash', hash('sha256', $secret))->first();
            if ($participant === null) {
                return $denied;
            }
            $session = DB::table('table_sessions')->where('id', $participant->table_session_id)->first();
            if (! in_array($session->status, TableSessions::LIVE, true)) {
                return ApiError::response('SESSION_CLOSED', 'This session is over. Scan the table QR again.', 409);
            }
            $request->attributes->add(['participant' => $participant, 'table_session' => $session]);

            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
