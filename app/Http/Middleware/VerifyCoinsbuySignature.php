<?php

namespace App\Http\Middleware;

use App\Models\Invoice;
use App\Models\UserCredential;
use App\Services\Payments\CoinsbuyGateway;
use App\Services\Payments\PaymentEnvironment;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a Coinsbuy callback. Same contract as VerifyEngineSecret:
 * secret from config (never env() at runtime), hash_equals, fail closed with
 * 503 when unconfigured, 401 on mismatch, {success, error_code, message} JSON.
 *
 * Coinsbuy signs in the BODY (`meta.sign`), not in a header — the headers are
 * only a fallback. The message is
 *   transfer.status . transfer.amount . deposit.tracking_id . meta.time
 * concatenated exactly as those values appear in the JSON.
 */
class VerifyCoinsbuySignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = app(PaymentEnvironment::class)->coinsbuy()['webhook_secret'];

        if ($secret === '') {
            return response()->json([
                'success' => false,
                'error_code' => 'COINSBUY_WEBHOOK_NOT_CONFIGURED',
                'message' => 'Coinsbuy webhook secret is not configured on the server.',
            ], 503);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (! is_array($payload)) {
            return response()->json([
                'success' => false,
                'error_code' => 'COINSBUY_PAYLOAD_INVALID',
                'message' => 'Callback body was not valid JSON.',
            ], 400);
        }

        $transfer = null;
        foreach ((array) ($payload['included'] ?? []) as $item) {
            if (($item['type'] ?? null) === 'transfer') {
                $transfer = $item;
                break;
            }
        }

        $message = self::rawScalar($transfer['attributes']['status'] ?? '')
            .self::rawScalar($transfer['attributes']['amount'] ?? '')
            .self::rawScalar($payload['data']['attributes']['tracking_id'] ?? '')
            .self::rawScalar($payload['meta']['time'] ?? '');

        $received = (string) (
            $payload['meta']['sign']
            ?? $request->header('X-Coinsbuy-Signature')
            ?? $request->header('Signature')
            ?? ''
        );

        $expected = hash_hmac('sha256', $message, $secret);
        $testCallback = false;

        if (! hash_equals($expected, $received)) {
            // A `developer` invoice was paid with the SANDBOX key set even on
            // the live box, so its callback carries the sandbox signature. Accept
            // that second secret only for an invoice whose owner is actually a
            // developer — never as a blanket fallback, or a leaked sandbox
            // secret would settle real invoices.
            $testCallback = self::signedAsDeveloperTest($payload, $message, $received, $secret);

            if (! $testCallback) {
                // The signed message is the thing that drifts (a numeric-typed
                // amount would re-format and break the HMAC), so log it — it is
                // not secret, and it turns a mystery into a one-look diagnosis.
                Log::debug('Coinsbuy signature mismatch.', ['signed_message' => $message]);

                return response()->json([
                    'success' => false,
                    'error_code' => 'COINSBUY_SIGNATURE_INVALID',
                    'message' => 'Invalid callback signature.',
                ], 401);
            }
        }

        $request->attributes->set('coinsbuy_payload', $payload);
        $request->attributes->set('coinsbuy_transfer', $transfer);
        $request->attributes->set('coinsbuy_test_callback', $testCallback);

        return $next($request);
    }

    /**
     * True when the callback is signed with the sandbox webhook secret AND its
     * tracking_id points at an invoice owned by a `developer` account — the
     * test-payment path from PaymentController::applyRoleOverrides().
     */
    private static function signedAsDeveloperTest(
        array $payload,
        string $message,
        string $received,
        string $machineSecret,
    ): bool {
        $sandboxSecret = (string) config('payments.coinsbuy.sandbox.webhook_secret', '');

        // Nothing to try when the machine already runs on sandbox keys.
        if ($sandboxSecret === '' || hash_equals($machineSecret, $sandboxSecret)) {
            return false;
        }

        $invoiceId = CoinsbuyGateway::parseTrackingId(
            isset($payload['data']['attributes']['tracking_id'])
                ? (string) $payload['data']['attributes']['tracking_id']
                : null
        );
        if ($invoiceId === null) {
            return false;
        }

        $userId = Invoice::query()->whereKey($invoiceId)->value('user_id');
        if ($userId === null) {
            return false;
        }

        $role = UserCredential::query()->whereKey($userId)->value('type');
        if ($role !== 'developer') {
            return false;
        }

        if (! hash_equals(hash_hmac('sha256', $message, $sandboxSecret), $received)) {
            return false;
        }

        Log::info('Coinsbuy: accepted a sandbox-signed callback for a developer invoice.', [
            'invoice_id' => $invoiceId,
        ]);

        return true;
    }

    /**
     * Stringify without letting PHP reformat what the provider signed. Strings
     * pass through untouched; numbers are printed in plain decimal, never in
     * scientific notation.
     */
    private static function rawScalar($value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value) || $value === null) {
            return '';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
        }

        return (string) $value;
    }
}
