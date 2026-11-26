<?php

namespace App\Http\Controllers;

use App\Application\CatalogProjection;
use App\Application\TableSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mestaro\Kernel\Auth\AccessClaims;
use Mestaro\Kernel\Http\ApiError;

/** The diner's view of its own session, and the staff's view of the restaurant's. */
final class SessionController
{
    public function __construct(private readonly TableSessions $sessions, private readonly CatalogProjection $projection) {}

    public function mine(Request $request): JsonResponse
    {
        $session = $request->attributes->get('table_session');

        return response()->json(['data' => [
            'session' => $this->sessions->forParticipant($session),
            'me' => ['id' => $request->attributes->get('participant')->public_id, 'display_name' => $request->attributes->get('participant')->display_name],
            'participants' => DB::table('table_session_participants')->where('table_session_id', $session->id)->count(),
        ]]);
    }

    public function index(): JsonResponse
    {
        $rows = DB::table('table_sessions')->whereIn('status', TableSessions::LIVE)->orderBy('created_at')->get();

        return response()->json(['data' => $rows->map($this->present(...))]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->transition($id, ['pending_approval'], [
            'status' => 'open', 'opened_at' => now(), 'approved_by' => $this->userId($request),
        ]);
    }

    /** Also how a request is refused. */
    public function close(Request $request, string $id): JsonResponse
    {
        return $this->transition($id, TableSessions::LIVE, [
            'status' => 'closed', 'closed_at' => now(), 'closed_by' => $this->userId($request),
        ]);
    }

    /**
     * One conditional update, so two people acting at once cannot both succeed.
     *
     * @param  list<string>  $from
     * @param  array<string, mixed>  $changes
     */
    private function transition(string $id, array $from, array $changes): JsonResponse
    {
        $id = strtolower($id);
        $done = DB::table('table_sessions')->where('public_id', $id)->whereIn('status', $from)
            ->update([...$changes, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        $session = DB::table('table_sessions')->where('public_id', $id)->first();
        if ($session === null) {
            return ApiError::response('NOT_FOUND', 'Session not found.', 404);
        }

        return $done === 0
            ? ApiError::response('INVALID_STATE_TRANSITION', 'The session is no longer in a state that allows this.', 409)
            : response()->json(['data' => $this->present($session)]);
    }

    // shortcut: two queries per session; join them when a restaurant has more live sessions than a screenful.
    /** @return array<string, mixed> */
    private function present(object $s): array
    {
        return [
            'id' => $s->public_id, 'table_id' => $s->table_id, 'table_label' => $this->projection->find('table', $s->table_id)['label'] ?? null,
            'status' => $s->status, 'join_code' => $s->join_code, 'version' => $s->version, 'created_at' => $s->created_at,
            'participants' => DB::table('table_session_participants')->where('table_session_id', $s->id)->count(),
        ];
    }

    private function userId(Request $request): string
    {
        /** @var AccessClaims $claims */
        $claims = $request->attributes->get('claims');

        return $claims->userId;
    }
}
