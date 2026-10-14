<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;
use Tmonier\SyliusBulkActionsPlugin\Action\Shipment\ShipShipmentAction;
use Tmonier\SyliusBulkActionsPlugin\Form\Type\BulkActionType;
use Tmonier\SyliusBulkActionsPlugin\Form\Type\ShipWithTrackingCodesType;
use Tmonier\SyliusBulkActionsPlugin\Processor\BulkActionProcessorInterface;
use Tmonier\SyliusBulkActionsPlugin\Processor\TooManyItemsException;
use Tmonier\SyliusBulkActionsPlugin\Registry\BulkActionRegistryInterface;
use Tmonier\SyliusBulkActionsPlugin\Report\BulkActionReport;
use Tmonier\SyliusBulkActionsPlugin\Shipment\TrackingCodeShipper;

/**
 * Grid selection (POST ids[]) -> confirmation page with the form of the action -> execution,
 * report in flash messages and redirection to the grid.
 */
final class BulkActionController extends AbstractController
{
    public const REPORT_FLASH = 'tmonier_bulk_actions_report';

    public const ADMIN_ROLE = 'ROLE_ADMINISTRATION_ACCESS';

    private const PREVIEW_LIMIT = 20;

    private const REPORT_DETAILS_LIMIT = 50;

    public function __construct(
        private readonly BulkActionRegistryInterface $registry,
        private readonly BulkActionProcessorInterface $processor,
        private readonly TranslatorInterface $translator,
        private readonly FormFactoryInterface $formFactory,
        private readonly TrackingCodeShipper $trackingCodeShipper,
    ) {
    }

    public function prepareAction(Request $request, string $code): Response
    {
        $action = $this->getAction($code);
        $redirect = $this->safeRedirect($request, $request->headers->get('referer'));

        $ids = $this->idsFromList($request->request->all()['ids'] ?? []);
        if ([] === $ids) {
            $this->addFlash('error', 'tmonier_bulk_actions.flash.no_selection');

            return new RedirectResponse($redirect);
        }
        if (count($ids) > $this->processor->getMaxItems()) {
            return $this->tooManyItems($redirect, count($ids));
        }

        $form = $this->createBulkForm($action, $code, ['ids' => implode(',', $ids), 'redirect' => $redirect]);

        return $this->renderConfirmation($action, $form, $ids, $redirect);
    }

    public function executeAction(Request $request, string $code): Response
    {
        $action = $this->getAction($code);

        $form = $this->createBulkForm($action, $code, null);
        $form->handleRequest($request);
        if (!$form->isSubmitted()) {
            return $this->redirectToRoute('sylius_admin_dashboard');
        }

        /** @var array{ids?: string|null, redirect?: string|null, data?: array<string, mixed>|null} $formData */
        $formData = $form->getData() ?? [];
        $ids = $this->idsFromList(explode(',', (string) ($formData['ids'] ?? '')));
        $redirect = $this->safeRedirect($request, $formData['redirect'] ?? null);

        if (!$form->isValid()) {
            return $this->renderConfirmation($action, $form, $ids, $redirect, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ([] === $ids) {
            $this->addFlash('error', 'tmonier_bulk_actions.flash.no_selection');

            return new RedirectResponse($redirect);
        }

        try {
            $report = $this->processor->process($action, $ids, $formData['data'] ?? []);
        } catch (TooManyItemsException $exception) {
            return $this->tooManyItems($redirect, $exception->count);
        }

        $this->flashReport($request, $action->getLabel(), $report);

        return new RedirectResponse($redirect);
    }

    /** Pasted "order number;tracking code" lines: one tracking code per shipment. */
    public function shipWithTrackingCodesAction(Request $request): Response
    {
        $action = $this->getAction(ShipShipmentAction::CODE);
        $redirect = $this->generateUrl('sylius_admin_shipment_index');

        $form = $this->formFactory->createNamed('tmonier_bulk_actions_tracking', ShipWithTrackingCodesType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{lines: string} $data */
            $data = $form->getData();

            try {
                $report = $this->trackingCodeShipper->ship($action, $data['lines']);
            } catch (TooManyItemsException $exception) {
                return $this->tooManyItems($redirect, $exception->count);
            }

            if ($report instanceof \Tmonier\SyliusBulkActionsPlugin\Report\BulkActionReport) {
                $this->flashReport($request, 'tmonier_bulk_actions.ui.ship_with_tracking_codes', $report);

                return new RedirectResponse($redirect);
            }

            $this->addFlash('error', 'tmonier_bulk_actions.flash.no_tracking_lines');
        }

        return $this->render('@TmonierSyliusBulkActionsPlugin/admin/shipment/ship_with_tracking_codes.html.twig', [
            'form' => $form->createView(),
            'redirect' => $redirect,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function getAction(string $code): BulkActionInterface
    {
        $this->denyAccessUnlessGranted(self::ADMIN_ROLE);

        $action = $this->registry->get($code);
        if (!$action instanceof \Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface) {
            throw new NotFoundHttpException(sprintf('Bulk action "%s" not found.', $code));
        }

        return $action;
    }

    /** @param array<string, mixed>|null $data */
    private function createBulkForm(BulkActionInterface $action, string $code, ?array $data): FormInterface
    {
        return $this->formFactory->createNamed('tmonier_bulk_actions', BulkActionType::class, $data, [
            'data_type' => $action->getFormType(),
            'action' => $this->generateUrl('tmonier_bulk_actions_admin_execute', ['code' => $code]),
        ]);
    }

    /** @param list<int|string> $ids */
    private function renderConfirmation(BulkActionInterface $action, FormInterface $form, array $ids, string $redirect, int $status = Response::HTTP_OK): Response
    {
        return $this->render('@TmonierSyliusBulkActionsPlugin/admin/bulk_action/confirm.html.twig', [
            'action' => $action,
            'form' => $form->createView(),
            'count' => count($ids),
            'preview' => $this->processor->preview($action, $ids, self::PREVIEW_LIMIT),
            'redirect' => $redirect,
        ], new Response(null, $status));
    }

    private function tooManyItems(string $redirect, int $count): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->addFlash('error', [
            'message' => 'tmonier_bulk_actions.flash.too_many_items',
            'parameters' => ['%count%' => $count, '%max%' => $this->processor->getMaxItems()],
        ]);

        return new RedirectResponse($redirect);
    }

    private function flashReport(Request $request, string $label, BulkActionReport $report): void
    {
        $type = 'success';
        if ($report->countSkipped() > 0) {
            $type = 'warning';
        }
        if ($report->countFailed() > 0) {
            $type = 'error';
        }

        $this->addFlash($type, [
            'message' => 'tmonier_bulk_actions.flash.report',
            'parameters' => [
                '%action%' => $this->translator->trans($label),
                '%applied%' => $report->countApplied(),
                '%skipped%' => $report->countSkipped(),
                '%failed%' => $report->countFailed(),
            ],
        ]);

        $details = [];
        foreach ($report->getItems() as $item) {
            if (BulkActionResult::APPLIED === $item->getStatus()) {
                continue;
            }
            $details[] = [
                'label' => $item->getLabel(),
                'status' => $item->getStatus(),
                'reason' => (string) $item->getResult()->getReason(),
                'parameters' => $item->getResult()->getParameters(),
            ];
        }
        if ([] === $details) {
            return;
        }

        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add(self::REPORT_FLASH, [
                'items' => array_slice($details, 0, self::REPORT_DETAILS_LIMIT),
                'more' => max(0, count($details) - self::REPORT_DETAILS_LIMIT),
            ]);
        }
    }

    /**
     * @return list<int>
     */
    private function idsFromList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $ids = [];
        foreach ($values as $value) {
            if ((is_string($value) || is_int($value)) && 1 === preg_match('/^\d{1,18}$/', trim((string) $value))) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    /** Only a local path (of this host) is accepted, anything else goes to the dashboard. */
    private function safeRedirect(Request $request, ?string $url): string
    {
        $fallback = $this->generateUrl('sylius_admin_dashboard');
        if (null === $url || '' === $url) {
            return $fallback;
        }

        $parts = parse_url($url);
        if (false === $parts) {
            return $fallback;
        }
        if (isset($parts['host']) && $parts['host'] !== $request->getHost()) {
            return $fallback;
        }

        $path = $parts['path'] ?? '';
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return $fallback;
        }

        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
