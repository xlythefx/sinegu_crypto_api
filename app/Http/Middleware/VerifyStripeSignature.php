<?php

namespace App\Http\Middleware;

use App\Services\Payments\StripeGateway;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a Stripe webhook delivery. Same contract as VerifyEngineSecret:
 * secret from config, fail closed with 503 when unconfigured, 401 on mismatch,
 * {success, error_code, message} JSON.
 *
 * The signature covers the RAW body, so this must use $request->getContent() —
 * a re-encoded $request->all() would not match. constructEvent also enforces
 * Stripe's 300-second timestamp tolerance, which is the replay guard.
 */
class VerifyStripeSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var StripeGateway $gateway */
        $gateway = app(StripeGateway::class);

        // Any mode: a developer's test-card checkout arrives signed with the
        // TEST secret even on the live box. What such an event may settle is
        // decided in the controller, not here.
        if ($gateway->webhookSecretsByMode() === []) {
            return response()->json([
                'success' => false,
                'error_code' => 'STRIPE_WEBHOOK_NOT_CONFIGURED',
                'message' => 'Stripe webhook secret is not configured on the server.',
            ], 503);
        }

        try {
            $event = $gateway->constructEventAnyMode(
                (string) $request->getContent(),
                (string) $request->header('Stripe-Signature', '')
            );
        } catch (SignatureVerificationException $e) {
            return response()->json([
                'success' => false,
                'error_code' => 'STRIPE_SIGNATURE_INVALID',
                'message' => 'Invalid webhook signature.',
            ], 401);
        } catch (\UnexpectedValueException $e) {
            return response()->json([
                'success' => false,
                'error_code' => 'STRIPE_PAYLOAD_INVALID',
                'message' => 'Webhook body was not valid JSON.',
            ], 400);
        } catch (\Throwable $e) {
            Log::error('Stripe webhook verification blew up.', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error_code' => 'STRIPE_SIGNATURE_INVALID',
                'message' => 'Could not verify the webhook signature.',
            ], 401);
        }

        // Verified once; the controller reads it from here rather than re-parsing.
        $request->attributes->set('stripe_event', $event);

        return $next($request);
    }
}
