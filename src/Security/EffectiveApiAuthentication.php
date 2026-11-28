<?php

declare(strict_types=1);

namespace PrimeBuilder\Security;

use Prime\Api\ApiError;
use Prime\Auth\Capabilities;
use Prime\Auth\Middleware\ApiAuthenticate;
use Prime\Auth\User;
use Prime\Auth\UserRepository;
use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Request;
use Prime\Http\Response;

/**
 * Prime 1.0.1 compatibility: retain the core authentication/CSRF checks and narrow
 * bearer abilities to the current account before any API authorization or handler.
 * Prime 1.0.2 performs this intersection in ApiTokenService itself.
 */
final class EffectiveApiAuthentication implements MiddlewareInterface
{
    public function __construct(
        private readonly ApiAuthenticate $core,
        private readonly UserRepository $users,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        return $this->core->process($request, function (Request $authenticated) use ($next): Response {
            // Sessions and anonymous requests must retain the CMS's normal behavior.
            if ($authenticated->attribute('api.token_id') === null) {
                return $next($authenticated);
            }
            $tokenUser = $authenticated->attribute('api.user');
            $tokenAbilities = $authenticated->attribute('api.abilities');
            if (!$tokenUser instanceof User || !is_array($tokenAbilities)) {
                return ApiError::response('unauthenticated', 401, $authenticated);
            }
            $account = $this->users->find($tokenUser->id);
            if (!$account instanceof User || !$account->isActive()) {
                return ApiError::response('unauthenticated', 401, $authenticated);
            }
            $abilities = array_values(array_unique(array_filter(
                $tokenAbilities,
                static fn (mixed $cap): bool => is_string($cap) && $cap !== '' && $cap !== Capabilities::WILDCARD && $account->can($cap),
            )));
            // Never pass the account roles or a literal wildcard to the scoped token user.
            $effective = new User(
                id: $account->id,
                email: $account->email,
                displayName: $account->displayName,
                status: $account->status,
                locale: $account->locale,
                sessionVersion: $account->sessionVersion,
                lastLoginAt: $account->lastLoginAt,
                passwordChangedAt: $account->passwordChangedAt,
                twoFactorConfirmedAt: $account->twoFactorConfirmedAt,
                twoFactorLastStep: $account->twoFactorLastStep,
                createdAt: $account->createdAt,
                updatedAt: $account->updatedAt,
                roles: [],
                capabilities: $abilities,
                avatarMediaId: $account->avatarMediaId,
                username: $account->username,
                bio: $account->bio,
            );
            return $next($authenticated->withAttributes(['api.user' => $effective, 'api.abilities' => $abilities]));
        });
    }
}
