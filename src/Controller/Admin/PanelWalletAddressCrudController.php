<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\PanelWalletAddress;
use App\Enum\PanelWalletAddressStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Read-only in the admin (see configureActions()): this IS how the pool
 * size is configured per (panel, currency, network) -- by how many
 * PanelWalletAddress rows the `app:panel-wallets:provision` command has
 * created for that combination. There's deliberately no separate "pool
 * size" number to keep in sync; admins view/monitor the pool here.
 */
final class PanelWalletAddressCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PanelWalletAddress::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Wallet pool address')
            ->setEntityLabelInPlural('Wallet address pool')
            ->setDefaultSort(['currency' => 'ASC', 'slotIndex' => 'ASC']);
    }

    /**
     * Pool addresses are only ever created via app:panel-wallets:provision
     * (needs a real panel API call) and only ever mutated by
     * WalletAddressPoolService's reserve/release -- no hand-editing.
     */
    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('panel');
        yield TextField::new('currency');
        yield TextField::new('network');
        yield IntegerField::new('slotIndex');
        yield TextField::new('address');
        yield TextField::new('addressTag')->hideOnIndex();
        yield ChoiceField::new('status')->setChoices([
            PanelWalletAddressStatus::FREE->value => PanelWalletAddressStatus::FREE->value,
            PanelWalletAddressStatus::HELD->value => PanelWalletAddressStatus::HELD->value,
        ])->renderAsBadges();
        yield TextField::new('heldByDepositRequestId')->hideOnIndex();
        yield DateTimeField::new('heldAt')->hideOnIndex();
        yield DateTimeField::new('createdAt');
    }
}
