<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\WithdrawalRequest;
use App\Enum\CallbackDeliveryStatus;
use App\Enum\PaymentRequestStatus;
use App\Enum\PaymentStatus;
use App\Panel\TestPanel\TestPanelSimulationException;
use App\Panel\TestPanel\TestPanelSimulator;
use App\Panel\Dto\WithdrawalExecutionRequest;
use App\Panel\Exception\PanelException;
use App\Panel\PanelRegistry;
use App\Service\CallbackDispatcher;
use App\Service\WithdrawalRequestService;
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
        private readonly WithdrawalRequestService $withdrawalRequestService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly TestPanelSimulator $testPanelSimulator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return WithdrawalRequest::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Заявка на вывод')
            ->setEntityLabelInPlural('Заявки на вывод')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('uuid', 'UUID (Okean)')->setTemplatePath('admin/field/copyable.html.twig');
        yield AssociationField::new('panel', 'Панель');
        yield TextField::new('currency', 'Валюта');
        yield TextField::new('network', 'Сеть')->hideOnIndex();
        yield ChoiceField::new('status', 'Статус')->setChoices(self::statusChoices())->renderAsBadges(self::statusBadgeTypes());
        yield TextField::new('amount', 'Сумма');
        yield TextField::new('destinationAddress', 'Адрес получателя')->hideOnIndex()->setTemplatePath('admin/field/copyable.html.twig');
        yield TextField::new('txHash', 'Хеш транзакции')->hideOnIndex()->setTemplatePath('admin/field/copyable.html.twig');
        yield TextField::new('failureReason', 'Причина ошибки')->hideOnIndex();
        yield AssociationField::new('payments', 'Платежи')->onlyOnDetail()->setTemplatePath('admin/field/payments.html.twig');
        yield ChoiceField::new('callbackStatus', 'Статус колбэка')->setChoices(self::callbackStatusChoices())->renderAsBadges(self::callbackStatusBadgeTypes())->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Создано');
        yield DateTimeField::new('updatedAt', 'Обновлено')->hideOnIndex();
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $actions->disable(Action::NEW, Action::DELETE, Action::EDIT);

        $retry = Action::new('retry', 'Повторить вывод')
            ->linkToCrudAction('retry')
            ->displayIf(static fn (WithdrawalRequest $w) => PaymentRequestStatus::SUBMIT_FAILED === $w->getStatus());

        $resendCallback = Action::new('resendCallback', 'Повторить отправку колбэка')
            ->linkToCrudAction('resendCallback')
            ->displayIf(static fn (WithdrawalRequest $w) => $w->getStatus()->isTerminal());

        $failPaused = Action::new('failPaused', 'Закрыть как ошибку')
            ->linkToCrudAction('failPaused')
            ->displayIf(static fn (WithdrawalRequest $w) => PaymentRequestStatus::PAUSED === $w->getStatus());

        $actions = $actions
            ->add(Crud::PAGE_INDEX, $failPaused)
            ->add(Crud::PAGE_DETAIL, $failPaused)
            ->add(Crud::PAGE_INDEX, $retry)
            ->add(Crud::PAGE_DETAIL, $retry)
            ->add(Crud::PAGE_INDEX, $resendCallback)
            ->add(Crud::PAGE_DETAIL, $resendCallback);

        $testActions = [
            'testProcessing' => ['Тестовая панель: в обработке', PaymentRequestStatus::PROCESSING],
            'testConfirm' => ['Тестовая панель: подтвердить вывод', PaymentRequestStatus::COMPLETED],
            'testFail' => ['Тестовая панель: ошибка', PaymentRequestStatus::FAILED],
            'testCancel' => ['Тестовая панель: отменить платёж', PaymentRequestStatus::PAUSED],
        ];
        foreach ($testActions as $method => [$label, $target]) {
            $action = Action::new($method, $label)
                ->linkToCrudAction($method)
                ->renderAsForm()
                ->displayIf(fn (WithdrawalRequest $w) => \in_array($target, $this->testPanelSimulator->availableWithdrawalTargets($w), true));

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

    #[AdminRoute(path: '/{entityId}/test-cancel', name: '_test_cancel')]
    public function testCancel(AdminContext $context): RedirectResponse
    {
        return $this->simulate($context, PaymentRequestStatus::PAUSED);
    }

    #[AdminRoute(path: '/{entityId}/fail-paused', name: '_fail_paused')]
    public function failPaused(AdminContext $context): RedirectResponse
    {
        /** @var WithdrawalRequest $withdrawalRequest */
        $withdrawalRequest = $context->getEntity()->getInstance();
        $this->withdrawalRequestService->failPaused($withdrawalRequest);

        return $this->redirect($context->getRequest()->headers->get('referer') ?? '/admin');
    }

    private function simulate(AdminContext $context, PaymentRequestStatus $target): RedirectResponse
    {
        /** @var WithdrawalRequest $withdrawalRequest */
        $withdrawalRequest = $context->getEntity()->getInstance();

        try {
            $this->testPanelSimulator->transitionWithdrawal($withdrawalRequest, $target);
            $this->addFlash('success', sprintf('[ТЕСТ-ПАНЕЛЬ] Заявка на вывод переведена в статус «%s» (вручную; ничего не отправлено; по терминальным статусам Okean получает колбэк).', $target->label()));
        } catch (TestPanelSimulationException $exception) {
            $this->addFlash('danger', '[ТЕСТ-ПАНЕЛЬ] '.$exception->getMessage());
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
            $withdrawalRequest->getLeadPayment()?->setStatus(PaymentStatus::PENDING)->setReason(null)->setPanelReference($result->panelWithdrawalReference);
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
            $choices[$case->label()] = $case->value;
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    private static function statusBadgeTypes(): array
    {
        $types = [];
        foreach (PaymentRequestStatus::cases() as $case) {
            $types[$case->value] = $case->badgeType();
        }

        return $types;
    }

    /**
     * @return array<string, string>
     */
    private static function callbackStatusChoices(): array
    {
        $choices = [];
        foreach (CallbackDeliveryStatus::cases() as $case) {
            $choices[$case->label()] = $case->value;
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    private static function callbackStatusBadgeTypes(): array
    {
        $types = [];
        foreach (CallbackDeliveryStatus::cases() as $case) {
            $types[$case->value] = $case->badgeType();
        }

        return $types;
    }
}
