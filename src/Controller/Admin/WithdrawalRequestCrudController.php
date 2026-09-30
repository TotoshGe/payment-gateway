<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\WithdrawalRequest;
use App\Enum\CallbackDeliveryStatus;
use App\Enum\PaymentRequestStatus;
use App\Enum\PaymentStatus;
use App\Panel\Dto\WithdrawalExecutionRequest;
use App\Panel\Exception\PanelException;
use App\Panel\PanelRegistry;
use App\Service\CallbackDispatcher;
use App\Service\Exception\WithdrawalPaymentException;
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
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
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
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
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
        yield TextField::new('id', 'ID заявки')->onlyOnDetail()->setTemplatePath('admin/field/copyable.html.twig');
        yield TextField::new('id', 'ID заявки')->onlyOnIndex()->setTemplatePath('admin/field/request_id_link.html.twig');
        yield TextField::new('uuid', 'UUID (Okean)')->setTemplatePath('admin/field/copyable.html.twig');
        yield AssociationField::new('panel', 'Панель');
        yield TextField::new('currency', 'Валюта');
        yield TextField::new('network', 'Сеть')->hideOnIndex();
        yield ChoiceField::new('status', 'Статус')->setChoices(self::statusChoices())->renderAsBadges(self::statusBadgeTypes());
        yield TextField::new('amount', 'Сумма')->setTemplatePath('admin/field/amount.html.twig');
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

        $createPayment = Action::new('createPayment', 'Создать платёж')
            ->linkToUrl(fn (WithdrawalRequest $w) => $this->adminUrlGenerator
                ->setController(self::class)
                ->setAction('createPayment')
                ->setEntityId($w->getId())
                ->set('_token', $this->csrfTokenManager->getToken(self::createPaymentTokenId($w))->getValue())
                ->generateUrl())
            ->renderAsForm()
            ->displayIf(static fn (WithdrawalRequest $w) => PaymentRequestStatus::AWAITING_PAYOUT === $w->getStatus() && $w->getPayments()->isEmpty());

        $actions = $actions
            ->add(Crud::PAGE_INDEX, $createPayment)
            ->add(Crud::PAGE_DETAIL, $createPayment)
            ->add(Crud::PAGE_INDEX, $failPaused)
            ->add(Crud::PAGE_DETAIL, $failPaused)
            ->add(Crud::PAGE_INDEX, $retry)
            ->add(Crud::PAGE_DETAIL, $retry)
            ->add(Crud::PAGE_INDEX, $resendCallback)
            ->add(Crud::PAGE_DETAIL, $resendCallback);

        return $actions;
    }

    #[AdminRoute(path: '/{entityId}/create-payment', name: '_create_payment', options: ['methods' => ['POST']])]
    public function createPayment(AdminContext $context): RedirectResponse
    {
        /** @var WithdrawalRequest $withdrawalRequest */
        $withdrawalRequest = $context->getEntity()->getInstance();
        $back = $context->getRequest()->headers->get('referer') ?? '/admin';

        if (!$this->isCsrfTokenValid(self::createPaymentTokenId($withdrawalRequest), (string) $context->getRequest()->query->get('_token'))) {
            $this->addFlash('danger', 'Недействительный CSRF-токен, платёж не создан.');

            return $this->redirect($back);
        }

        try {
            $payment = $this->withdrawalRequestService->createPayment($withdrawalRequest);
            if (PaymentStatus::FAILED === $payment->getStatus()) {
                $this->addFlash('warning', sprintf('Платёж создан, но панель вернула ошибку: %s. Заявка приостановлена.', $payment->getReason()));
            } else {
                $this->addFlash('success', 'Платёж создан, вывод отправлен на панель.');
            }
        } catch (WithdrawalPaymentException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirect($back);
    }

    public static function createPaymentTokenId(WithdrawalRequest $withdrawalRequest): string
    {
        return 'create-payment-'.$withdrawalRequest->getId();
    }

    #[AdminRoute(path: '/{entityId}/fail-paused', name: '_fail_paused')]
    public function failPaused(AdminContext $context): RedirectResponse
    {
        /** @var WithdrawalRequest $withdrawalRequest */
        $withdrawalRequest = $context->getEntity()->getInstance();
        $this->withdrawalRequestService->failPaused($withdrawalRequest);

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
