<?php

namespace App\Http\Controllers;

use App\Services\Ai\McpServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /api/mcp — the read-only AI connector (Model Context Protocol,
 * Streamable HTTP). Reached only through `ai.key` (VerifyAiAccessKey) and the
 * `ai` throttle; see McpServer for the protocol and AiTools for what it reads.
 */
class McpController extends Controller
{
    public function handle(Request $request, McpServer $server): JsonResponse|Response
    {
        $message = json_decode($request->getContent(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json($server->error(null, -32700, 'The body is not valid JSON.'), 400);
        }

        [$status, $body] = $server->handle($message, $request->attributes->get('ai_key'), $request->ip());

        return $body === null
            ? response('', $status)
            : response()->json($body, $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** GET / DELETE /api/mcp — no server-initiated stream, no sessions. */
    public function notAllowed(): Response
    {
        return response('', 405, ['Allow' => 'POST']);
    }
}
