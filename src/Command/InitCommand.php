<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:init',
    description: 'Initialisation of the app',
)]
class InitCommand extends Command
{
    private EntityManagerInterface $em;
    private ParameterBagInterface $params;
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(EntityManagerInterface $em, ParameterBagInterface $params, UserPasswordHasherInterface $passwordHasher)
    {
        $this->em = $em;
        $this->params = $params;
        $this->passwordHasher = $passwordHasher;

        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('APP:INIT');
        $io->text('Initialisation of the app');
        $io->text('');

        $admins = array_map('trim', explode(',', $this->params->get('appAdmin')));
        foreach ($admins as $admin) {
            $user = $this->em->getRepository("App\Entity\User")->findOneBy(['username' => $admin]);
            if (!$user) {
                $io->text('> Création du compte admin par defaut = '.$admin);
                $user = new User();

                $hashedPassword = $this->passwordHasher->hashPassword(
                    $user,
                    $this->params->get('appSecret')
                );

                $user->setUsername($admin);
                $user->setPassword($hashedPassword);
                $user->setAvatar('medias/avatar/admin.jpg');
                $user->setEmail($this->params->get('appNoreply'));
                $this->em->persist($user);
            }

            $user->setRoles(['ROLE_ADMIN']);
            $this->em->flush();
        }

        return Command::SUCCESS;
    }
}
