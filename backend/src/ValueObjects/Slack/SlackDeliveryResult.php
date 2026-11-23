<?php

namespace IvyForms\ValueObjects\Slack;

/**
 * Outcome of a Slack message delivery attempt.
 *
 * Distinguishes transient failures (transport errors, rate limits, 5xx)
 * that are worth retrying from permanent Slack rejections (invalid channel,
 * revoked auth, message too long) that must not burn retry attempts.
 */
final class SlackDeliveryResult
{
    public bool $ok;
    public bool $retryable;
    public string $error;
    public ?int $retryAfterSeconds;

    public function __construct(bool $ok, bool $retryable, string $error, ?int $retryAfterSeconds)
    {
        $this->ok = $ok;
        $this->retryable = $retryable;
        $this->error = $error;
        $this->retryAfterSeconds = $retryAfterSeconds;
    }

    public static function success(): self
    {
        return new self(true, false, '', null);
    }

    public static function transientFailure(string $error, ?int $retryAfterSeconds = null): self
    {
        return new self(false, true, $error, $retryAfterSeconds);
    }

    public static function permanentFailure(string $error): self
    {
        return new self(false, false, $error, null);
    }
}
