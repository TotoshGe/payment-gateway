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
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
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
            ->setEntityLabelInSingular('Доставка колбэка')
            ->setEntityLabelInPlural('Доставка колбэков')
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
            ->add(TextFilter::new('eventType', 'Тип события'))
            ->add(ChoiceFilter::new('status', 'Статус')->setChoices(self::statusChoices()))
            ->add(DateTimeFilter::new('createdAt', 'Создано'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('eventType', 'Тип события');
        yield TextField::new('requestTypeLabel', 'Тип заявки');
        yield TextField::new('requestIdLabel', 'ID заявки');
        yield ChoiceField::new('status', 'Статус')->setChoices(self::statusChoices())->renderAsBadges(self::statusBadgeTypes());
        yield IntegerField::new('attempt', 'Попытка');
        yield IntegerField::new('lastResponseCode', 'Код последнего ответа')->hideOnIndex();
        yield TextField::new('lastError', 'Последняя ошибка')->hideOnIndex();
        yield ArrayField::new('attemptLog', 'Журнал попыток')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Создано');
        yield DateTimeField::new('updatedAt', 'Обновлено')->hideOnIndex();
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $actions->disable(Action::NEW, Action::DELETE, Action::EDIT);

        $resend = Action::new('resend', 'Повторить отправку')
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
        foreach (CallbackDeliveryStatus::cases() as $case) {
            $types[$case->value] = $case->badgeType();
        }

        return $types;
    }
}
