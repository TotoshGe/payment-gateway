<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\PanelRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class PanelRouteCrudController extends AbstractCrudController
{
    public function __construct(private readonly string $defaultPanelCode)
    {
    }

    public static function getEntityFqcn(): string
    {
        return PanelRoute::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Маршрут')
            ->setEntityLabelInPlural('Маршрутизация')
            ->setDefaultSort(['currency' => 'ASC', 'network' => 'ASC'])
            ->setHelp('index', sprintf(
                'Панель по умолчанию (для монет без маршрута): <strong>%s</strong> (переменная окружения DEFAULT_PANEL). '.
                'Порядок выбора: точный маршрут валюта+сеть, затем маршрут валюты с пустой сетью, затем панель по умолчанию.',
                htmlspecialchars($this->defaultPanelCode),
            ));
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('currency', 'Валюта');
        yield TextField::new('network', 'Сеть (пусто = любая)')->setRequired(false);
        yield AssociationField::new('panel', 'Панель');
        yield BooleanField::new('enabled', 'Включён');
        yield DateTimeField::new('updatedAt', 'Обновлён')->hideOnForm();
    }
}
