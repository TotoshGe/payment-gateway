<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\DepositRequest;
use App\Enum\PaymentRequestStatus;
use App\Panel\TestPanel\TestPanelSimulationException;
use App\Panel\TestPanel\TestPanelSimulator;
use App\Service\CallbackDispatcher;
use App\Service\DepositRequestService;
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
use Symfony\Component\HttpFoundation\RedirectResponse;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;

/**
 * Read-mostly: admins can't hand-edit money fields, only intervene on
 * stuck/failed requests via the two explicit actions below.
 */
final class DepositRequestCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly DepositRequestService $depositRequestService,
        private readonly CallbackDispatcher $callbackDispatcher,
        private readonly EntityManagerInterface $entityManager,
        private readonly TestPanelSimulator $testPanelSimulator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return DepositRequest::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Deposit request')
            ->setEntityLabelInPlural('Deposit requests')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('externalReference');
        yield AssociationField::new('panel');
        yield TextField::new('currency');
        yield TextField::new('network')->hideOnIndex();
        yield ChoiceField::new('status')->setChoices(self::statusChoices())->renderAsBadges();
        yield TextField::new('expectedAmount');
        yield TextField::new('receivedAmount')->hideOnIndex();
        yield TextField::new('address')->hideOnIndex();
        yield ChoiceField::new('callbackStatus')->hideOnIndex();
        yield DateTimeField::new('expiresAt')->hideOnIndex();
        yield DateTimeField::new('lastPolledAt')->hideOnIndex();
        yield DateTimeField::new('createdAt');
        yield DateTimeField::new('updatedAt')->hideOnIndex();
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $actions->disable(Action::NEW, Action::DELETE, Action::EDIT);

        $markExpired = Action::new('markExpired', 'Mark as expired')
            ->linkToCrudAction('markExpired')
            ->displayIf(static fn (DepositRequest $d) => PaymentRequestStatus::AWAITING_PAYMENT === $d->getStatus());

        $resendCallback = Action::new('resendCallback', 'Resend callback')
            ->linkToCrudAction('resendCallback')
            ->displayIf(static fn (DepositRequest $d) => $d->getStatus()->isTerminal());

        $actions = $actions
            ->add(Crud::PAGE_INDEX, $markExpired)
            ->add(Crud::PAGE_DETAIL, $markExpired)
            ->add(Crud::PAGE_INDEX, $resendCallback)
            ->add(Crud::PAGE_DETAIL, $resendCallback);

        $testActions = [
            'testReceived' => ['Test Panel: mark received', PaymentRequestStatus::RECEIVED],
            'testConfirm' => ['Test Panel: confirm payment', PaymentRequestStatus::COMPLETED],
        ];
        foreach ($testActions as $method => [$label, $target]) {
            $action = Action::new($method, $label)
                ->linkToCrudAction($method)
                ->renderAsForm()
                ->displayIf(fn (DepositRequest $d) => \in_array($target, $this->testPanelSimulator->availableDepositTargets($d), true));

            $actions = $actions->add(Crud::PAGE_INDEX, $action)->add(Crud::PAGE_DETAIL, $action);
        }

        return $actions;
    }

    #[AdminRoute(path: '/{entityId}/test-received', name: '_test_received')]
    public function testReceived(AdminContext $context): RedirectResponse
    {
        return $this->simulate($context, PaymentRequestStatus::RECEIVED);
    }

    #[AdminRoute(path: '/{entityId}/test-confirm', name: '_test_confirm')]
    public function testConfirm(AdminContext $context): RedirectResponse
    {
        return $this->simulate($context, PaymentRequestStatus::COMPLETED);
    }

    private function simulate(AdminContext $context, PaymentRequestStatus $target): RedirectResponse
    {
        /** @var DepositRequest $depositRequest */
        $depositRequest = $context->getEntity()->getInstance();

        try {
            $this->testPanelSimulator->transitionDeposit($depositRequest, $target);
            $this->addFlash('success', sprintf('[TEST PANEL] Deposit moved to "%s" (manual; Okean is called back for terminal statuses).', $target->value));
        } catch (TestPanelSimulationException $exception) {
            $this->addFlash('danger', '[TEST PANEL] '.$exception->getMessage());
        }

        return $this->redirect($context->getRequest()->headers->get('referer') ?? '/admin');
    }

    #[AdminRoute(path: '/{entityId}/mark-expired', name: '_mark_expired')]
    public function markExpired(AdminContext $context): RedirectResponse
    {
        /** @var DepositRequest $depositRequest */
        $depositRequest = $context->getEntity()->getInstance();
        $this->depositRequestService->expire($depositRequest);

        return $this->redirect($context->getRequest()->headers->get('referer') ?? '/admin');
    }

    #[AdminRoute(path: '/{entityId}/resend-callback', name: '_resend_callback')]
    public function resendCallback(AdminContext $context): RedirectResponse
    {
        /** @var DepositRequest $depositRequest */
        $depositRequest = $context->getEntity()->getInstance();
        $this->callbackDispatcher->dispatchFor($depositRequest);

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
