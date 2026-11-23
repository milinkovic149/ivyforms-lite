<?php

namespace IvyForms\Services\Integrations;

/**
 * Verifies RSA-SHA256 signatures from ivyforms-middleware (Amelia-compatible).
 */
class MiddlewareSignatureService
{
//     private const MIDDLEWARE_PUBLIC_KEY = <<<'PEM'
// -----BEGIN PUBLIC KEY-----
// MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAuBviz1x8ocjJnoGmum6E
// vBG1jz5Hnr/FNA9lYnoYVCP3P/gFm77AFP+vfl+jCLyWzB22uD8uoea/stUmP9U9
// 0bqVT5jMZorb4BoesPD8fG+iz8WzdfotRo/xatlhyZkE+9nE45BWHwZulf0ff1P8
// IKi6FLtXukqngJ+m63fJ0GelAH8KuMj6gzM1O+SSojGO+HBCdwlCcrxBal5M2x3Z
// dSKBh3U+qdCtnRSahkAO3DU7O2d9U+HZWBaKUJcb0LB1pM9mUlb3oFIaxQjLNdIk
// krr9L9okRlHeB1MqxfraexHRMmcrq1jawAerFIwSQrcZHGiQalbi3PKYZFEJgxqX
// WQIDAQAB
// -----END PUBLIC KEY-----
// PEM;

    private const MIDDLEWARE_PUBLIC_KEY = <<<'PEM'
    -----BEGIN PUBLIC KEY-----
    MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAriCZqL/Ob/3QtdBs1ZPJ
    QhlHOX6/21VfqSESflh1CNcMlzpX+Qgf8htcwBJfeQur1fJ1G60x1IEjZy7lvgDS
    s+UN2HDBIKX5tImBNafBg6WwJW7Wf0GcEyniQTqMEvJfQ3YOWJpfC4MfPlztyT/4
    2nfB1tbl0RPPvfNPO4+3vjjSARUsoJqZ+H9M1urEPr75TuyuTxB8UdQaqWxWBnoY
    lPOfojDPlg18eWuqKeJfOvy0QcrcJBWcgLFLqrPRUXD/V04lVtOmzS4Vftafs3Ob
    qY3vxOh4BMuMzqtlcGZ4bB79yC6L91rBR7+Bq+HVkJsEExmcX1uWXoNJ/G+Ser1i
    UwIDAQAB
    -----END PUBLIC KEY-----
    PEM;

    /**
     * Bound to the callback URL and one-time state this site sent when it started the connect flow,
     * so a delivery signed for one site does not verify on another. Must match ivyforms-middleware
     * SquareService::buildTokenDeliveryStringToSign byte for byte.
     *
     * @param array<string, mixed> $params Square token delivery POST body from middleware.
     */
    public function buildTokenDeliverySignatureString(array $params, string $callbackUrl, string $siteState): string
    {
        return '/square/authorization/token/v2?' . http_build_query(
            [
                'callback_url' => $callbackUrl,
                'site_state' => $siteState,
                'issued_at' => (string) ($params['issued_at'] ?? ''),
                'access_token' => (string) ($params['access_token'] ?? ''),
                'expires_at' => (string) ($params['expires_at'] ?? ''),
                'refresh_token' => (string) ($params['refresh_token'] ?? ''),
                'merchant_id' => (string) ($params['merchant_id'] ?? ''),
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }

    /**
     * Max allowed age (and clock skew) of a signed token delivery, in seconds.
     */
    private const TOKEN_DELIVERY_TTL = 300;

    /**
     * @param array<string, mixed> $params Token delivery POST body from middleware.
     */
    public function buildSlackTokenDeliverySignatureString(array $params): string
    {
        $canonical = [
            'path'         => '/slack/authorization/token',
            'access_token' => (string) ($params['access_token'] ?? ''),
            'team_id'      => (string) ($params['team_id'] ?? ''),
            'team_name'    => (string) ($params['team_name'] ?? ''),
            'bot_user_id'  => (string) ($params['bot_user_id'] ?? ''),
            'callback_url' => (string) ($params['callback_url'] ?? ''),
            'state'        => (string) ($params['state'] ?? ''),
            'issued_at'    => (string) ($params['issued_at'] ?? ''),
        ];

        // Must match middleware SlackService::buildTokenDeliverySignatureString exactly (do not use
        // wp_json_encode — older WP builds may still escape slashes and break RSA verification).
        $encoded = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '';
    }

    /**
     * The signature binds the payload to this site's callback URL and to a
     * timestamp, so a payload signed for another site (or captured earlier)
     * cannot be replayed against this endpoint.
     *
     * @param array<string, mixed> $params
     */
    public function verifyTokenDeliverySignature(array $params, string $callbackUrl, string $siteState): bool
    {
        $signature = isset($params['signature']) ? (string) $params['signature'] : '';

        return $this->verifySignature(
            $this->buildTokenDeliverySignatureString($params, $callbackUrl, $siteState),
            $signature
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    public function verifySlackTokenDeliverySignature(array $params): bool
    {
        $issuedAt = $params['issued_at'] ?? null;
        if (!is_numeric($issuedAt)) {
            return false;
        }

        if (abs(time() - (int) $issuedAt) > self::TOKEN_DELIVERY_TTL) {
            return false;
        }

        $signature = isset($params['signature']) ? (string) $params['signature'] : '';

        return $this->verifySignature($this->buildSlackTokenDeliverySignatureString($params), $signature);
    }

    /**
     * @param mixed $data Webhook payload data (encoded the same way middleware signs it).
     */
    public function verifyWebhookDataSignature($data, ?string $signature): bool
    {
        $encoded = json_encode($data);

        if (!is_string($encoded)) {
            return false;
        }

        return $this->verifySignature($encoded, $signature ?? '');
    }

    public function verifySignature(string $stringToVerify, string $signature): bool
    {
        if ($stringToVerify === '' || $signature === '') {
            return false;
        }

        $publicKey = openssl_pkey_get_public(self::MIDDLEWARE_PUBLIC_KEY);
        if ($publicKey === false) {
            return false;
        }

        $decodedSignature = base64_decode($signature, true);
        if ($decodedSignature === false) {
            return false;
        }

        return openssl_verify($stringToVerify, $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }
}
