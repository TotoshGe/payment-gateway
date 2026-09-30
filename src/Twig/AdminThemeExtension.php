<?php

declare(strict_types=1);

namespace App\Twig;

use App\Admin\AdminTheme;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The login page is rendered by a plain Symfony controller (not routed
 * through AbstractDashboardController), so Dashboard::configureAssets()
 * never applies to it -- it needs the stylesheet linked explicitly.
 */
final class AdminThemeExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('pg_amount', self::trimAmount(...))];
    }

    /** Display only: 55.000000000000000000 -> 55, 0.00118500 -> 0.001185. */
    public static function trimAmount(mixed $value): string
    {
        $value = (string) $value;
        if (1 !== preg_match('/^\d+\.\d+$/', $value)) {
            return $value;
        }

        return rtrim(rtrim($value, '0'), '.');
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('admin_theme_css', AdminTheme::cssUrl(...)),
        ];
    }
}
