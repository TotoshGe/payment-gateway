<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\DepositRequest;
use App\Enum\PaymentRequestStatus;
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

        return $actions
            ->add(Crud::PAGE_INDEX, $markExpired)
            ->add(Crud::PAGE_DETAIL, $markExpired)
            ->add(Crud::PAGE_INDEX, $resendCallback)
            ->add(Crud::PAGE_DETAIL, $resendCallback);
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
