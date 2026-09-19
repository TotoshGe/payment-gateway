<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\WithdrawalRequest;
use App\Enum\PaymentRequestStatus;
use App\Panel\BinanceTest\BinanceTestSimulationException;
use App\Panel\BinanceTest\BinanceTestSimulator;
use App\Panel\Dto\WithdrawalExecutionRequest;
use App\Panel\Exception\PanelException;
use App\Panel\PanelRegistry;
use App\Service\CallbackDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;

final class WithdrawalRequestCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly PanelRegistry $panelRegistry,
        private readonly CallbackDispatcher $callbackDispatcher,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly BinanceTestSimulator $binanceTestSimulator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return WithdrawalRequest::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Withdrawal request')
            ->setEntityLabelInPlural('Withdrawal requests')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('externalReference');
        yield AssociationField::new('panel');
        yield TextField::new('currency');
        yield TextField::new('network')->hideOnIndex();
        yield ChoiceField::new('status')->setChoices(self::statusChoices())->renderAsBadges();
        yield TextField::new('amount');
        yield TextField::new('destinationAddress')->hideOnIndex();
        yield TextField::new('txHash')->hideOnIndex();
        yield TextField::new('failureReason')->hideOnIndex();
        yield ChoiceField::new('callbackStatus')->hideOnIndex();
        yield DateTimeField::new('createdAt');
        yield DateTimeField::new('updatedAt')->hideOnIndex();
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $actions->disable(Action::NEW, Action::DELETE, Action::EDIT);

        $retry = Action::new('retry', 'Retry withdrawal')
            ->linkToCrudAction('retry')
            ->displayIf(static fn (WithdrawalRequest $w) => PaymentRequestStatus::SUBMIT_FAILED === $w->getStatus());

        $resendCallback = Action::new('resendCallback', 'Resend callback')
            ->linkToCrudAction('resendCallback')
            ->displayIf(static fn (WithdrawalRequest $w) => $w->getStatus()->isTerminal());

        $actions = $actions
            ->add(Crud::PAGE_INDEX, $retry)
            ->add(Crud::PAGE_DETAIL, $retry)
            ->add(Crud::PAGE_INDEX, $resendCallback)
            ->add(Crud::PAGE_DETAIL, $resendCallback);

        $testActions = [
            'testProcessing' => ['TEST: mark processing', PaymentRequestStatus::PROCESSING],
            'testConfirm' => ['TEST: confirm withdrawal', PaymentRequestStatus::COMPLETED],
            'testFail' => ['TEST: fail', PaymentRequestStatus::FAILED],
        ];
        foreach ($testActions as $method => [$label, $target]) {
            $action = Action::new($method, $label)
                ->linkToCrudAction($method)
                ->renderAsForm()
                ->displayIf(fn (WithdrawalRequest $w) => \in_array($target, $this->binanceTestSimulator->availableWithdrawalTargets($w), true));

            $actions = $actions->add(Crud::PAGE_INDEX, $action)->add(Crud::PAGE_DETAIL, $action);
        }

        return $actions;
    }

    #[AdminRoute(path: '/{entityId}/test-processing', name: '_test_processing')]
    public function testProcessing(AdminContext $context): RedirectResponse
    {
        return $this->simulate($context, PaymentRequestStatus::PROCESSING);
    }

    #[AdminRoute(path: '/{entityId}/test-confirm', name: '_test_confirm')]
    public function testConfirm(AdminContext $context): RedirectResponse
    {
        return $this->simulate($context, PaymentRequestStatus::COMPLETED);
    }

    #[AdminRoute(path: '/{entityId}/test-fail', name: '_test_fail')]
    public function testFail(AdminContext $context): RedirectResponse
    {
        return $this->simulate($context, PaymentRequestStatus::FAILED);
    }

    private function simulate(AdminContext $context, PaymentRequestStatus $target): RedirectResponse
    {
        /** @var WithdrawalRequest $withdrawalRequest */
        $withdrawalRequest = $context->getEntity()->getInstance();

        try {
            $this->binanceTestSimulator->transitionWithdrawal($withdrawalRequest, $target);
            $this->addFlash('success', sprintf('[BINANCE TEST] Withdrawal moved to "%s" (simulated; nothing was sent; Okean is called back for terminal statuses).', $target->value));
        } catch (BinanceTestSimulationException $exception) {
            $this->addFlash('danger', '[BINANCE TEST] '.$exception->getMessage());
        }

        return $this->redirect($context->getRequest()->headers->get('referer') ?? '/admin');
    }

    /**
     * Re-submits to the panel using the SAME clientWithdrawalId (Binance
     * withdrawOrderId) that was generated at creation -- this can never
     * result in sending funds twice, it either finds the panel already
     * processed the original attempt (idempotent no-op) or genuinely
     * retries a call that never reached the panel. See ARCHITECTURE.md 5.
     */
    #[AdminRoute(path: '/{entityId}/retry', name: '_retry')]
    public function retry(AdminContext $context): RedirectResponse
    {
        /** @var WithdrawalRequest $withdrawalRequest */
        $withdrawalRequest = $context->getEntity()->getInstance();
        $panel = $withdrawalRequest->getPanel();

        try {
            $driver = $this->panelRegistry->getDriverFor($panel);
            $result = $driver->executeWithdrawal($panel, new WithdrawalExecutionRequest(
                currency: $withdrawalRequest->getCurrency(),
                network: $withdrawalRequest->getNetwork(),
                amount: $withdrawalRequest->getAmount(),
                destinationAddress: $withdrawalRequest->getDestinationAddress(),
                destinationTag: $withdrawalRequest->getDestinationTag(),
                clientWithdrawalId: $withdrawalRequest->getClientWithdrawalId(),
            ));

            $withdrawalRequest->setPanelWithdrawalReference($result->panelWithdrawalReference);
            $withdrawalRequest->setFailureReason(null);
            $withdrawalRequest->setStatus($result->status);
            $this->entityManager->flush();
        } catch (PanelException $exception) {
            $this->logger->error('Manual withdrawal retry failed', [
                'withdrawalRequestId' => (string) $withdrawalRequest->getId(),
                'error' => $exception->getMessage(),
            ]);
            $withdrawalRequest->setFailureReason($exception->getMessage());
            $this->entityManager->flush();
        }

        return $this->redirect($context->getRequest()->headers->get('referer') ?? '/admin');
    }

    #[AdminRoute(path: '/{entityId}/resend-callback', name: '_resend_callback')]
    public function resendCallback(AdminContext $context): RedirectResponse
    {
        /** @var WithdrawalRequest $withdrawalRequest */
        $withdrawalRequest = $context->getEntity()->getInstance();
        $this->callbackDispatcher->dispatchFor($withdrawalRequest);

        return $this->redirect($context->getRequest()->headers->get('referer') ?? '/admin');
    }

    /**
     * @return array<string, string>
     */
    private static function statusChoices(): array
    {
        $choices = [];
        foreach (PaymentRequestStatus::cases() as $case) {
            $choices[$case->value] = $case->value;
        }

        return $choices;
    }
}
