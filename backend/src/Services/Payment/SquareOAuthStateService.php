<?php

namespace IvyForms\Services\Payment;

use IvyForms\Services\Integrations\MiddlewareSignatureService;

/**
 * One-time state for a Square connect flow started by an administrator on this site.
 *
 * Middleware signs this state and the callback URL into the token delivery, so a delivery signed
 * for another site, or one this site has already accepted, is refused.
 */
class SquareOAuthStateService
{
    private const TRANSIENT = 'ivyforms_square_oauth_state';
    private const PENDING_TRANSIENT_PREFIX = 'ivyforms_square_oauth_pending_';
    private const TTL_SECONDS = 900;
    private const MAX_DELIVERY_AGE_SECONDS = 300;

    private MiddlewareSignatureService $middlewareSignatureService;

    public function __construct(MiddlewareSignatureService $middlewareSignatureService)
    {
        $this->middlewareSignatureService = $middlewareSignatureService;
    }

    /**
     * Start a connect flow. Starting another one replaces the unfinished flow.
     *
     * @return string State to send to middleware together with the callback URL.
     */
    public function create(string $callbackUrl): string
    {
        $previous = get_transient(self::TRANSIENT);
        if (is_array($previous) && !empty($previous['state'])) {
            delete_transient(self::PENDING_TRANSIENT_PREFIX . (string) $previous['state']);
        }

        $state = wp_generate_password(32, false, false);

        // Kept under a key of its own, so ending this flow cannot delete the state of a newer one.
        set_transient(self::PENDING_TRANSIENT_PREFIX . $state, 1, self::TTL_SECONDS);
        set_transient(
            self::TRANSIENT,
            [
                'state'       => $state,
                'callbackUrl' => $callbackUrl,
            ],
            self::TTL_SECONDS
        );

        return $state;
    }

    /**
     * Accept a token delivery only when middleware signed it for the pending connect flow, then end that flow.
     *
     * @param array<string, mixed> $params Token delivery POST body from middleware.
     */
    public function consumeDelivery(array $params): bool
    {
        $pending = get_transient(self::TRANSIENT);
        if (!is_array($pending) || empty($pending['state']) || empty($pending['callbackUrl'])) {
            return false;
        }

        $isSigned = $this->middlewareSignatureService->verifyTokenDeliverySignature(
            $params,
            (string) $pending['callbackUrl'],
            (string) $pending['state']
        );

        if (!$isSigned || !$this->isFreshDelivery($params)) {
            return false;
        }

        // Deletes only the verified state. If a newer connect replaced it in the meantime, the delete fails
        // and the delivery is refused. Only one request can delete it, so a racing copy is refused as well.
        return delete_transient(self::PENDING_TRANSIENT_PREFIX . (string) $pending['state']);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function isFreshDelivery(array $params): bool
    {
        $issuedAt = (string) ($params['issued_at'] ?? '');

        return preg_match('/^\d{1,12}$/', $issuedAt) === 1
            && abs(time() - (int) $issuedAt) <= self::MAX_DELIVERY_AGE_SECONDS;
    }
}
