<?php

namespace App\Controller;

use Jumbojett\OpenIDConnectClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route(path: '/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();

        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    #[Route('/auth/callback', name: 'app_auth_callback')]
    public function callback(): Response
    {
        $oidc = new OpenIDConnectClient(
            $this->getParameter('oidcIssuer'),
            $this->getParameter('oidcClientId'),
            $this->getParameter('oidcClientSecret')
        );

        $oidc->setRedirectURL($this->generateUrl('app_auth_callback', [], 0)); // URL absolue
        $oidc->addScope(['openid', 'profile', 'email']);

        // Cette ligne déclenche l'échange du "code" contre le "token"
        if ($oidc->authenticate()) {
            $userInfo = $oidc->requestUserInfo();

            // 1. Ici, vous devez gérer votre session Symfony
            // Ex: $session->set('user_email', $userInfo->email);

            // 2. Une fois authentifié, on redirige enfin vers la Home
            return $this->redirectToRoute('app_home');
        }

        return new Response("Erreur d'authentification", 403);
    }
}
