<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class UserController extends AbstractController
{
    #[Route('/admin/user', name: 'app_admin_user')]
    public function list(UserRepository $userRepository): Response
    {
        $users = $userRepository->findAll();

        return $this->render('user/list.html.twig', [
            'usemenu' => true,
            'usesidebar' => true,
            'title' => 'Liste des Utilisateurs',
            'routesubmit' => 'app_admin_user_submit',
            'routeupdate' => 'app_admin_user_update',
            'users' => $users,
        ]);
    }

    #[Route('/admin/user/submit', name: 'app_admin_user_submit')]
    public function submit(Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $em): Response
    {
        $user = new User();

        $form = $this->createForm(UserType::class, $user, ['mode' => 'submit', 'modeAuth' => $this->getParameter('modeAuth')]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $user = $form->getData();
            $password = $user->getPassword();
            if ('CAS' === $this->getParameter('modeAuth')) {
                $password = Uuid::uuid4();
            }

            $hashedPassword = $passwordHasher->hashPassword(
                $user,
                $password
            );
            $user->setPassword($hashedPassword);

            $em->persist($user);
            $em->flush();

            return $this->redirectToRoute('app_admin_user');
        }

        return $this->render('user/edit.html.twig', [
            'usemenu' => true,
            'usesidebar' => true,
            'title' => 'Création Utilisateur',
            'routecancel' => 'app_admin_user',
            'routedelete' => 'app_admin_user_delete',
            'mode' => 'submit',
            'form' => $form,
        ]);
    }

    #[Route('/admin/user/update/{id}', name: 'app_admin_user_update')]
    public function update(int $id, Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $em): Response
    {
        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            return $this->redirectToRoute('app_admin_user');
        }
        $hashedPassword = $user->getPassword();

        $form = $this->createForm(UserType::class, $user, ['mode' => 'update', 'modeAuth' => $this->getParameter('modeAuth')]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $user = $form->getData();
            if ($user->getPassword()) {
                $hashedPassword = $passwordHasher->hashPassword(
                    $user,
                    $user->getPassword()
                );
            }
            $user->setPassword($hashedPassword);
            $em->flush();

            return $this->redirectToRoute('app_admin_user');
        }

        return $this->render('user/edit.html.twig', [
            'usemenu' => true,
            'usesidebar' => true,
            'title' => 'Modification Utilisateur = '.$user->getUsername(),
            'routecancel' => 'app_admin_user',
            'routedelete' => 'app_admin_user_delete',
            'mode' => 'update',
            'form' => $form,
        ]);
    }

    #[Route('/admin/user/delete/{id}', name: 'app_admin_user_delete')]
    public function delete(int $id, EntityManagerInterface $em): Response
    {
        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            return $this->redirectToRoute('app_admin_user');
        }

        // Tentative de suppression
        try {
            $em->remove($user);
            $em->flush();
        } catch (\Exception $e) {
            $this->addflash('error', $e->getMessage());

            return $this->redirectToRoute('app_admin_user_update', ['id' => $id]);
        }

        return $this->redirectToRoute('app_admin_user');
    }

    #[Route('/user', name: 'app_user_profil')]
    public function profil(Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $em): Response
    {
        $user = $em->getRepository(User::class)->find($this->getUser());
        if (!$user) {
            return $this->redirectToRoute('app_home');
        }
        $hashedPassword = $user->getPassword();

        $form = $this->createForm(UserType::class, $user, ['mode' => 'profil', 'modeAuth' => $this->getParameter('modeAuth')]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $user = $form->getData();
            if ($user->getPassword()) {
                $hashedPassword = $passwordHasher->hashPassword(
                    $user,
                    $user->getPassword()
                );
            }
            $user->setPassword($hashedPassword);

            $em->flush();

            return $this->redirectToRoute('app_home');
        }

        return $this->render('user/edit.html.twig', [
            'usemenu' => true,
            'usesidebar' => false,
            'title' => 'Profil = '.$user->getUsername(),
            'routecancel' => 'app_home',
            'routedelete' => '',
            'mode' => 'profil',
            'form' => $form,
        ]);
    }
}
