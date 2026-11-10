<?php

declare(strict_types=1);

namespace FreeImageToVideo;

/**
 * Base of every error this library throws. Catch a subclass to decide what to do next:
 * AuthError · QuotaError · PlanError · BusyError · RejectedError · GenerationError ·
 * TimeoutError · ApiError.
 */
abstract class FreeImageToVideoException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        /** Envelope code: -1, -2 or -3. */
        public readonly int $envelopeCode = 0,
        /** The envelope's data.reason, e.g. insufficient_credits. */
        public readonly ?string $reason = null,
        public readonly ?string $taskId = null,
        /** Failed runs: data.errorMessage. */
        public readonly ?string $errorMessage = null,
        /** Failed runs: data.failureKind. */
        public readonly ?string $failureKind = null,
    ) {
        parent::__construct($message);
    }

    /** Map an HTTP status and envelope to the error that says what to do next. */
    public static function classify(int $status, array $env): self
    {
        $msg = isset($env['message']) && is_string($env['message']) && $env['message'] !== ''
            ? $env['message'] : "request failed (HTTP $status)";
        $code = is_int($env['code'] ?? null) ? $env['code'] : 0;
        $reason = is_array($env['data'] ?? null) && is_string($env['data']['reason'] ?? null) ? $env['data']['reason'] : null;
        $class = match (true) {
            $status === 401 => AuthError::class,
            $code === -2 || $code === -3 => RejectedError::class,
            $reason === 'insufficient_credits' => QuotaError::class,
            $reason === 'plan_required' => PlanError::class,
            $reason === 'concurrency' => BusyError::class,
            str_contains(strtolower($msg), 'no auth') => AuthError::class,
            default => ApiError::class,
        };
        return new $class($msg, $status, $code, $reason);
    }
}
