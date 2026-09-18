<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CallbackDelivery;
use App\Enum\CallbackDeliveryStatus;
use App\Service\CallbackDispatcher;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\RedirectResponse;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;

final class CallbackDeliveryCrudController extends AbstractCrudController
{
    public function __construct(private readonly CallbackDispatcher $callbackDispatcher)
    {
    }

    public static function getEntityFqcn(): string
    {
        return CallbackDelivery::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Callback delivery')
            ->setEntityLabelInPlural('Callback deliveries')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    /**
     * requestType/requestId are enum/uuid-valued and EasyAdmin's default
     * auto-filter tries to cast them to plain strings -- restrict filters
     * to the fields that are actually safe/useful to filter by.
     */
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('eventType')
            ->add('status')
            ->add('createdAt');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('eventType');
        yield TextField::new('requestTypeLabel', 'Request type');
        yield TextField::new('requestIdLabel', 'Request ID');
        yield ChoiceField::new('status')->setChoices(self::statusChoices())->renderAsBadges();
        yield IntegerField::new('attempt');
        yield IntegerField::new('lastResponseCode')->hideOnIndex();
        yield TextField::new('lastError')->hideOnIndex();
        yield ArrayField::new('attemptLog')->onlyOnDetail();
        yield DateTimeField::new('createdAt');
        yield DateTimeField::new('updatedAt')->hideOnIndex();
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $actions->disable(Action::NEW, Action::DELETE, Action::EDIT);

        $resend = Action::new('resend', 'Resend now')
            ->linkToCrudAction('resend')
            ->displayIf(static fn (CallbackDelivery $d) => \in_array($d->getStatus(), [CallbackDeliveryStatus::FAILED, CallbackDeliveryStatus::EXHAUSTED], true));

        return $actions
            ->add(Crud::PAGE_INDEX, $resend)
            ->add(Crud::PAGE_DETAIL, $resend);
    }

    #[AdminRoute(path: '/{entityId}/resend', name: '_resend')]
    public function resend(AdminContext $context): RedirectResponse
    {
        /** @var CallbackDelivery $delivery */
        $delivery = $context->getEntity()->getInstance();
        $this->callbackDispatcher->redispatch($delivery);

        return $this->redirect($context->getRequest()->headers->get('referer') ?? '/admin');
    }

    /**
     * @return array<string, string>
     */
    private static function statusChoices(): array
    {
        $choices = [];
        foreach (CallbackDeliveryStatus::cases() as $case) {
            $choices[$case->value] = $case->value;
        }

        return $choices;
    }
}
