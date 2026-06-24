<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class ApiKeyAuthenticator implements AuthenticatorInterface
{
    public function __construct(
        private string $appSecret,
    ) {}

    public function supports(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/v1/api');
    }

    public function authenticate(Request $request): Passport
    {
        $secret = $request->headers->get('X-API-SECRET');

        if ($secret === null || $secret !== $this->appSecret) {
            throw new AuthenticationException('Invalid or missing API secret.');
        }

        $user = new InMemoryUser('api_user', '', ['ROLE_API']);

        return new SelfValidatingPassport(
            new UserBadge('api_user', fn () => $user)
        );
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $user = new InMemoryUser('api_user', '', ['ROLE_API']);

        return new PreAuthenticatedToken(
            $user,
            $firewallName,
            $user->getRoles()
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?JsonResponse
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'Unauthorized', 'message' => $exception->getMessage()],
            JsonResponse::HTTP_UNAUTHORIZED
        );
    }
}
