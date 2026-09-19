<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Panel;
use App\Security\PanelCredentialsEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * `credentials` is write-only: EntityFqcnFieldsCollection never shows it on
 * INDEX/DETAIL, and the form field binds to Panel::plainCredentialsInput,
 * which is encrypted into encryptedCredentials on persist/update and never
 * populated back from the stored ciphertext (see Panel entity docblock and
 * persistEntity()/updateEntity() below) -- same "never round-trips a
 * secret back into a form" pattern as a password field.
 */
final class PanelCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly PanelCredentialsEncryptor $credentialsEncryptor,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Panel::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Panel')
            ->setEntityLabelInPlural('Panels')
            ->setDefaultSort(['code' => 'ASC']);
    }

    public function createEntity(string $entityFqcn): Panel
    {
        return new Panel('', '');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('code');
        yield TextField::new('label');
        yield BooleanField::new('active');
        yield TextareaField::new('plainCredentialsInput', 'Credentials (JSON, write-only)')
            ->setFormTypeOption('required', false)
            ->hideOnIndex()
            ->hideOnDetail()
            ->setHelp('Enter as {"apiKey": "...", "apiSecret": "..."}. Leave blank to keep the current value unchanged. Never displayed once saved.');
        yield TextareaField::new('supportedCurrenciesJson', 'Supported currencies (JSON)')
            ->setFormTypeOption('required', false)
            ->onlyOnForms()
            ->setHelp('e.g. [{"currency": "USDT", "network": "TRC20"}]');
        yield TextareaField::new('configJson', 'Config (JSON)')
            ->setFormTypeOption('required', false)
            ->onlyOnForms()
            ->setHelp('Non-secret panel config, e.g. {"baseUrl": "...", "subAccounts": {"USDT:TRC20": ["sub1@x.com"]}}.');
        yield DateTimeField::new('createdAt')->hideOnForm();
        yield DateTimeField::new('updatedAt')->hideOnForm();
    }

    public function persistEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        $this->applyCredentials($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        $this->applyCredentials($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    private function applyCredentials(mixed $entityInstance): void
    {
        if (!$entityInstance instanceof Panel) {
            return;
        }

        $plain = $entityInstance->getPlainCredentialsInput();
        if (null === $plain || '' === trim($plain)) {
            return;
        }

        $decoded = json_decode($plain, true);
        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException('Credentials must be a valid JSON object.');
        }

        $entityInstance->setEncryptedCredentials($this->credentialsEncryptor->encrypt($decoded));
        $entityInstance->setPlainCredentialsInput(null);
        $entityInstance->touch();
    }
}
