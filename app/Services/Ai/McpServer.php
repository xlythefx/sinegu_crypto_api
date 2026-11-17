<?php

namespace App\Services\Ai;

use App\Models\AiAccessKey;
use App\Services\Ai\Tools\GuideTools;

/**
 * A minimal Model Context Protocol server over Streamable HTTP — tools only,
 * stateless, one JSON-RPC message per POST, answered as application/json.
 *
 * Hand-rolled (like the Discord OAuth) rather than a package: the surface a
 * tools-only server needs is five methods, and owning it keeps the auth and
 * logging in this codebase's terms. What it implements:
 *  - initialize       → protocol version, `tools` capability, server instructions
 *  - ping             → {}
 *  - tools/list       → AiTools::list()
 *  - tools/call       → AiTools::run(); a tool's own failure is a RESULT with
 *                       isError (the spec's rule: the AI should see it and retry)
 *  - notifications/*  → accepted, nothing to answer (HTTP 202)
 * Anything else is JSON-RPC -32601. No sessions (no Mcp-Session-Id), no SSE
 * stream, no resources or prompts.
 */
final class McpServer
{
    /** Every version this tools-only subset is valid under, newest first. */
    public const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    public const FALLBACK_VERSION = '2025-06-18';

    public const SERVER_VERSION = '1.0.0';

    public function __construct(private AiTools $tools, private AiAccessKeys $keys) {}

    /**
     * @param  mixed  $message  the decoded JSON-RPC body
     * @return array{0: int, 1: array<string, mixed>|null} HTTP status and body (null = no body)
     */
    public function handle(mixed $message, ?AiAccessKey $key, ?string $ip): array
    {
        $started = hrtime(true);

        if (! is_array($message) || array_is_list($message)) {
            // A JSON-RPC batch (dropped from MCP in 2025-06-18) or not an object at all.
            return [400, $this->error(null, -32600, 'Send one JSON-RPC request object per POST.')];
        }
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        if (($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($method)) {
            return [400, $this->error($id, -32600, 'Invalid JSON-RPC request.')];
        }
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        // A notification (no id) never gets a JSON-RPC answer.
        if (! array_key_exists('id', $message)) {
            return [202, null];
        }

        $tool = null;
        $arguments = null;
        $ok = true;
        $errorText = null;

        switch ($method) {
            case 'initialize':
                $requested = is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : null;
                $body = $this->result($id, [
                    // Echo a version we know; otherwise the most widely supported one.
                    'protocolVersion' => in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::FALLBACK_VERSION,
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => [
                        'name' => 'pixel-alpha',
                        'title' => $key?->can_create ? 'Pixel Alpha' : 'Pixel Alpha (read-only)',
                        'version' => self::SERVER_VERSION,
                    ],
                    'instructions' => GuideTools::INSTRUCTIONS.($key?->can_create ? GuideTools::CREATE_INSTRUCTIONS : ''),
                ]);
                break;

            case 'ping':
                $body = $this->result($id, (object) []);
                break;

            case 'tools/list':
                $body = $this->result($id, ['tools' => $this->tools->list($key)]);
                break;

            case 'tools/call':
                $tool = is_string($params['name'] ?? null) ? $params['name'] : '';
                $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
                if (! $this->tools->has($tool, $key)) {
                    $ok = false;
                    $errorText = "Unknown tool: {$tool}";
                    $body = $this->error($id, -32602, $errorText);
                    break;
                }
                [$ok, $text] = $this->tools->run($tool, $arguments, $key);
                $errorText = $ok ? null : $text;
                $body = $this->result($id, [
                    'content' => [['type' => 'text', 'text' => $text]],
                    'isError' => ! $ok,
                ]);
                break;

            default:
                $ok = false;
                $errorText = "Method not supported: {$method}";
                $body = $this->error($id, -32601, $errorText);
        }

        $bytes = strlen((string) json_encode($body));
        $this->keys->log($key, $method, $tool, $arguments, $ok, $errorText, $bytes,
            (int) round((hrtime(true) - $started) / 1e6), $ip);

        return [200, $body];
    }

    private function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    public function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
