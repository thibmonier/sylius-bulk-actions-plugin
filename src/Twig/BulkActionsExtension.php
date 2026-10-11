<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Twig;

use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;
use Tmonier\SyliusBulkActionsPlugin\Registry\BulkActionRegistryInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class BulkActionsExtension extends AbstractExtension
{
    public function __construct(private readonly BulkActionRegistryInterface $registry)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tmonier_bulk_actions', $this->getActions(...)),
        ];
    }

    /**
     * @param string|list<string>|null $codes restricts and orders the actions when given
     *
     * @return list<BulkActionInterface>
     */
    public function getActions(string $resource, string|array|null $codes = null): array
    {
        $actions = $this->registry->getForResource($resource);
        if (null === $codes) {
            return $actions;
        }

        $byCode = [];
        foreach ($actions as $action) {
            $byCode[$action->getCode()] = $action;
        }

        $result = [];
        foreach ((array) $codes as $code) {
            if (isset($byCode[$code])) {
                $result[] = $byCode[$code];
            }
        }

        return $result;
    }
}
