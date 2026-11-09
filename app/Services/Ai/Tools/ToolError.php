<?php

namespace App\Services\Ai\Tools;

use RuntimeException;

/**
 * A tool refused its arguments. The message goes back to the AI as the tool's
 * answer (`isError: true`) so it can fix the call — so it must read as an
 * instruction ("from must be YYYY-MM-DD"), never as a stack trace.
 */
final class ToolError extends RuntimeException {}
