<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

class AuthGuardEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        #[Autowire(param: 'modeAuth')]
        private string $modeAuth,
        private OidcClient $oidcClient,
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire(param: 'casHost')]
        private string $casHost,
        #[Autowire(param: 'casPort')]
        private string $casPort,
        #[Autowire(param: 'casPath')]
        private string $casPath,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        // Retour du fournisseur sans état en session : le cookie a été perdu
        // (autre nom de cookie, changement d'hôte, expiration). Sans ce
        // garde-fou, on relance une autorisation et le navigateur boucle.
        if ('OIDC' === $this->modeAuth
            && $request->query->has('code')
            && !$request->getSession()->has(OidcClient::SESSION_STATE_KEY)) {
            return new Response(
                '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
                .'<title>Session perdue</title></head><body>'
                .'<h1>Session perdue pendant l\'authentification</h1>'
                .'<p>Le fournisseur a bien renvoyé un code d\'autorisation, mais la session'
                .' ne le retrouve pas : le cookie de session n\'a pas été conservé.</p>'
                .'<p><a href="'.$this->urlGenerator->generate('app_login').'">Réessayer</a></p>'
                .'</body></html>',
                Response::HTTP_BAD_REQUEST,
                ['Content-Type' => 'text/html; charset=utf-8']
            );
        }

        return match ($this->modeAuth) {
            'CAS' => $this->startCas($request),
            'OIDC' => new RedirectResponse($this->oidcClient->createAuthorizationUrlSymfonySession($request->getSession())),
            default => new RedirectResponse($this->urlGenerator->generate('app_login')),
        };
    }

    private function startCas(Request $request): Response
    {
        // Récupérer l'hôte d'origine derrière le reverse proxy
        $host = $request->headers->get('host');
        $scheme = $request->headers->get('X-Forwarded-Proto') ?? $request->getScheme();

        \phpCAS::client(
            CAS_VERSION_2_0,
            $this->casHost,
            (int) $this->casPort,
            $this->casPath,
            $scheme.'://'.$host,
            false);
        \phpCAS::setNoCasServerValidation();
        \phpCAS::forceAuthentication();

        return new Response('', Response::HTTP_FOUND);
    }
}
