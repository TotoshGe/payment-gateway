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
            ->setEntityLabelInSingular('Панель')
            ->setEntityLabelInPlural('Панели')
            ->setDefaultSort(['code' => 'ASC']);
    }

    public function createEntity(string $entityFqcn): Panel
    {
        return new Panel('', '');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('code', 'Код');
        yield TextField::new('label', 'Название');
        yield BooleanField::new('active', 'Активна');
        yield TextareaField::new('plainCredentialsInput', 'Учётные данные (JSON, только для записи)')
            ->setFormTypeOption('required', false)
            ->hideOnIndex()
            ->hideOnDetail()
            ->setHelp('Введите в формате {"apiKey": "...", "apiSecret": "..."}. Оставьте пустым, чтобы сохранить текущее значение без изменений. После сохранения никогда не отображается.');
        yield TextareaField::new('supportedCurrenciesJson', 'Поддерживаемые валюты (JSON)')
            ->setFormTypeOption('required', false)
            ->onlyOnForms()
            ->setHelp('Например: [{"currency": "USDT", "network": "TRC20"}]');
        yield TextareaField::new('configJson', 'Конфигурация (JSON)')
            ->setFormTypeOption('required', false)
            ->onlyOnForms()
            ->setHelp('Несекретная конфигурация панели, например {"baseUrl": "...", "subAccounts": {"USDT:TRC20": ["sub1@x.com"]}}.');
        yield DateTimeField::new('createdAt', 'Создано')->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Обновлено')->hideOnForm();
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
            throw new \InvalidArgumentException('Учётные данные должны быть валидным JSON-объектом.');
        }

        $entityInstance->setEncryptedCredentials($this->credentialsEncryptor->encrypt($decoded));
        $entityInstance->setPlainCredentialsInput(null);
        $entityInstance->touch();
    }
}
