<?php

declare(strict_types=1);

namespace App\Controller\Security;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class AdminLoginController extends AbstractController
{
    /**
     * `_locale: ru` mirrors config/routes/easyadmin.yaml -- the login page
     * lives outside that resource (plain Symfony route) but is still part
     * of the admin panel, so it needs the same locale.
     */
    #[Route('/admin/login', name: 'admin_login', defaults: ['_locale' => 'ru'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'page_title' => 'payment-gateway',
            'action' => $this->generateUrl('admin_login'),
            'csrf_token_intention' => 'authenticate',
            'target_path' => $this->generateUrl('admin'),
            'username_label' => 'Email',
            'translation_domain' => 'messages',
        ]);
    }
}
