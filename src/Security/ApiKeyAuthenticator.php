<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Static X-Api-Key header check for Okean -> PG calls (see ARCHITECTURE.md
 * 3). Deliberately a different secret/mechanism from the PG -> Okean
 * callback signature (WebhookSigner) -- inbound and outbound directions
 * must not share a compromise blast radius.
 */
final class ApiKeyAuthenticator extends AbstractAuthenticator
{
    public function __construct(private readonly string $apiKey)
    {
    }

    /**
     * Always true (not conditional on the header being present): this
     * authenticator is the only one on the "api" firewall, so if it
     * declined unsupported requests, a request with no X-Api-Key header at
     * all would fall through to Symfony's default HTML error page instead
     * of the JSON error contract callers of this API expect.
     */
    public function supports(Request $request): ?bool
    {
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $provided = (string) $request->headers->get('X-Api-Key');

        if ('' === $provided || '' === $this->apiKey || !hash_equals($this->apiKey, $provided)) {
            throw new AuthenticationException('Invalid API key.');
        }

        return new SelfValidatingPassport(new UserBadge('okean', static fn () => new ApiClientUser()));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => 'Invalid or missing API key.'], 401);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(['error' => 'API key required.'], 401);
    }
}
