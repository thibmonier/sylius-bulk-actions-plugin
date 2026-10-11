<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Registry;

use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;

final class BulkActionRegistry implements BulkActionRegistryInterface
{
    /** @var array<string, BulkActionInterface>|null */
    private ?array $actions = null;

    /**
     * @param iterable<BulkActionInterface> $actions
     * @param list<string> $disabledCodes
     */
    public function __construct(
        private readonly iterable $taggedActions,
        private readonly array $disabledCodes = [],
    ) {
    }

    public function get(string $code): ?BulkActionInterface
    {
        return $this->all()[$code] ?? null;
    }

    public function getForResource(string $resource): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (BulkActionInterface $action): bool => $action->getResource() === $resource,
        ));
    }

    public function all(): array
    {
        if (null !== $this->actions) {
            return $this->actions;
        }

        $actions = [];
        foreach ($this->actions_ as $action) {
            $code = $action->getCode();
            if (isset($actions[$code])) {
                throw new \LogicException(sprintf('Two bulk actions use the code "%s" (%s and %s).', $code, $actions[$code]::class, $action::class));
            }
            if (!in_array($code, $this->disabledCodes, true)) {
                $actions[$code] = $action;
            }
        }

        return $this->actions = $actions;
    }
}
