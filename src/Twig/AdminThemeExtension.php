<?php

declare(strict_types=1);

namespace App\Twig;

use App\Admin\AdminTheme;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The login page is rendered by a plain Symfony controller (not routed
 * through AbstractDashboardController), so Dashboard::configureAssets()
 * never applies to it -- it needs the stylesheet linked explicitly.
 */
final class AdminThemeExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('admin_theme_css', AdminTheme::cssUrl(...)),
        ];
    }
}
