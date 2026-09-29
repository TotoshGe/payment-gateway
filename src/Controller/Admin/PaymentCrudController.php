<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Payment;
use App\Enum\PaymentRequestType;
use App\Enum\PaymentStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;

/**
 * Read-only: a payment's status is written only by the panel pollers (and the
 * Test Panel simulator), and the request status follows from it. Manual
 * intervention happens on the request, not by editing a payment.
 */
final class PaymentCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Payment::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Платёж')
            ->setEntityLabelInPlural('Платежи')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Статус')->setChoices(self::statusChoices()))
            ->add(DateTimeFilter::new('createdAt', 'Создан'));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::DELETE, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield ChoiceField::new('type', 'Тип')->setChoices([
            PaymentRequestType::DEPOSIT->label() => PaymentRequestType::DEPOSIT,
            PaymentRequestType::WITHDRAWAL->label() => PaymentRequestType::WITHDRAWAL,
        ]);
        yield AssociationField::new('depositRequest', 'Заявка на пополнение')->setTemplatePath('admin/field/association_link.html.twig');
        yield AssociationField::new('withdrawalRequest', 'Заявка на вывод')->setTemplatePath('admin/field/association_link.html.twig');
        yield AssociationField::new('panel', 'Панель');
        yield TextField::new('amount', 'Сумма');
        yield TextField::new('currency', 'Валюта');
        yield TextField::new('network', 'Сеть');
        yield ChoiceField::new('status', 'Статус')->setChoices(self::statusChoices())->renderAsBadges(self::statusBadgeTypes());
        yield IntegerField::new('confirmations', 'Подтверждения')->hideOnIndex();
        yield TextField::new('panelReference', 'Референс панели')->hideOnIndex()->setTemplatePath('admin/field/copyable.html.twig');
        yield TextField::new('txHash', 'Хеш транзакции')->hideOnIndex()->setTemplatePath('admin/field/copyable.html.twig');
        yield TextField::new('reason', 'Причина ошибки/отмены')->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Создан');
        yield DateTimeField::new('updatedAt', 'Обновлён')->hideOnIndex();
    }

    /**
     * @return array<string, string>
     */
    private static function statusChoices(): array
    {
        $choices = [];
        foreach (PaymentStatus::cases() as $case) {
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
        foreach (PaymentStatus::cases() as $case) {
            $types[$case->value] = $case->badgeType();
        }

        return $types;
    }
}
