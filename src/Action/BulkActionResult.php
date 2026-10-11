<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Action;

/**
 * Outcome of a bulk action on one item. A skipped item was left untouched on purpose (transition
 * not allowed, nothing to change...), a failed one hit an error.
 *
 * The optional "after flush" callback of an applied result runs once the change is flushed, e.g.
 * to dispatch the "post" resource event that sends an e-mail, exactly like the single action.
 */
final class BulkActionResult
{
    public const APPLIED = 'applied';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    /**
     * @param array<string, string|int|float> $parameters
     */
    private function __construct(
        private readonly string $status,
        private readonly ?string $reason = null,
        private readonly array $parameters = [],
        private readonly ?\Closure $afterFlush = null,
    ) {
    }

    public static function applied(?\Closure $afterFlush = null): self
    {
        return new self(self::APPLIED, null, [], $afterFlush);
    }

    /**
     * @param string $reason translation key (flashes domain)
     * @param array<string, string|int|float> $parameters
     */
    public static function skipped(string $reason, array $parameters = []): self
    {
        return new self(self::SKIPPED, $reason, $parameters);
    }

    /**
     * @param string $reason translation key (flashes domain) or raw error message
     * @param array<string, string|int|float> $parameters
     */
    public static function failed(string $reason, array $parameters = []): self
    {
        return new self(self::FAILED, $reason, $parameters);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isApplied(): bool
    {
        return self::APPLIED === $this->status;
    }

    public function isSkipped(): bool
    {
        return self::SKIPPED === $this->status;
    }

    public function isFailed(): bool
    {
        return self::FAILED === $this->status;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    /** @return array<string, string|int|float> */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getAfterFlush(): ?\Closure
    {
        return $this->afterFlush;
    }
}
