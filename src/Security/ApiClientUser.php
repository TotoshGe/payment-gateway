<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Not backed by a DB row on purpose: there's a single shared static API key
 * for Okean (see ARCHITECTURE.md 3), so there's no per-client account to
 * model yet. If that changes (multiple callers, per-client keys), this is
 * the seam to swap for a real entity + provider.
 */
final class ApiClientUser implements UserInterface
{
    public function getRoles(): array
    {
        return ['ROLE_API'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'okean';
    }
}
