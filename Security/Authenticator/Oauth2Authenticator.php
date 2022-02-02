<?php

namespace FOS\OAuthServerBundle\Security\Authenticator;

use FOS\OAuthServerBundle\Model\AccessToken;
use FOS\OAuthServerBundle\Security\Authentication\Token\OAuthToken;
use FOS\OAuthServerBundle\Security\Authenticator\Passport\Badge\AccessTokenBadge;
use OAuth2\OAuth2;
use OAuth2\OAuth2AuthenticateException;
use OAuth2\OAuth2ServerException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\PassportInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class Oauth2Authenticator extends AbstractAuthenticator
{
    /**
     * @var UserCheckerInterface
     */
    protected $userChecker;

    /**
     * @var OAuth2
     */
    protected $serverService;

    public function __construct(OAuth2 $serverService, UserCheckerInterface $userChecker )
    {
        $this->serverService = $serverService;
        $this->userChecker = $userChecker;
    }

    /**
     * @inheritDoc
     */
    public function supports(Request $request): ?bool
    {
        return $request->headers->has('Authorization');
    }

    /**
     * @inheritDoc
     */
    public function authenticate(Request $request): PassportInterface
    {
        try {
            $tokenString = str_replace('Bearer ', '', $request->headers->get('Authorization'));

            /** @var AccessToken $accessToken */
            $accessToken = $this->serverService->verifyAccessToken($tokenString);

            $user = $accessToken->getUser();
            $client = $accessToken->getClient();

            if (null !== $user) {
                try {
                    $this->userChecker->checkPreAuth($user);
                } catch (AccountStatusException $e) {
                    throw new OAuth2AuthenticateException(
                        Response::HTTP_UNAUTHORIZED,
                        OAuth2::TOKEN_TYPE_BEARER,
                        $this->serverService->getVariable(OAuth2::CONFIG_WWW_REALM),
                        'access_denied',
                        $e->getMessage()
                    );
                }
            }

            $roles = [];
            $scope = $accessToken->getScope();

            if (! empty($scope)) {
                foreach (explode(' ', $scope) as $role) {
                    $roles[] = 'ROLE_' . mb_strtoupper($role);
                }
            }

            $roles = array_unique($roles, SORT_REGULAR);

            $accessTokenBadge = new AccessTokenBadge( $accessToken, $roles );

            return new SelfValidatingPassport( new UserBadge( $user->getUserIdentifier() ), [ $accessTokenBadge ] );
        } catch (OAuth2ServerException $e) {
            throw new AuthenticationException('OAuth2 authentication failed', 0, $e);
        }
    }

    public function createAuthenticatedToken(PassportInterface $passport, string $firewallName): TokenInterface
    {
        /** @var AccessTokenBadge $accessTokenBadge */
        $accessTokenBadge = $passport->getBadge( AccessTokenBadge::class );
        $token = new OAuthToken( $accessTokenBadge->getRoles() );
        $token->setAuthenticated(true);
        $token->setUser($accessTokenBadge->getAccessToken()->getUser());
        $token->setToken( $accessTokenBadge->getAccessToken()->getToken() );

        return $token;
    }

    /**
     * @inheritDoc
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $data = [
            // you may want to customize or obfuscate the message first
            'message' => strtr($exception->getMessageKey(), $exception->getMessageData())

            // or to translate this message
            // $this->translator->trans($exception->getMessageKey(), $exception->getMessageData())
        ];

        return new JsonResponse($data, Response::HTTP_UNAUTHORIZED);
    }
}
