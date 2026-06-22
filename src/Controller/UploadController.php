<?php

namespace App\Controller;

use App\Service\ImageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class UploadController extends AbstractController
{
    private ImageService $imageService;

    public function __construct(ImageService $imageService)
    {
        $this->imageService = $imageService;
    }

    #[Route('/user/upload/crop01/{endpoint}', name: 'app_user_upload_crop01')]
    public function crop01(string $endpoint, Request $request): Response
    {
        $reportThumb = $request->get('reportThumb');

        return $this->render('upload\crop01.html.twig', [
            'useheader' => false,
            'usemenu' => false,
            'usesidebar' => false,
            'endpoint' => $endpoint,
            'reportThumb' => $reportThumb,
        ]);
    }

    #[Route('/user/upload/crop02', name: 'app_user_upload_crop02')]
    public function crop02(Request $request): Response
    {
        $reportThumb = $request->get('reportThumb');
        $path = $request->get('path');
        $file = $request->get('file');
        $image = $this->getParameter('kernel.project_dir').'/public/'.$path.'/'.$file;
        $thumb = $this->getParameter('kernel.project_dir').'/public/'.$path.'/thumb_'.$file;

        // Redimentionner
        $this->imageService->resizeImage($image, 700, 700);

        // Construction du formulaire
        $form = $this->createFormBuilder()
            ->add('submit', SubmitType::class, ['label' => 'Valider', 'attr' => ['class' => 'btn btn-success']])
            ->add('x1', HiddenType::class)
            ->add('y1', HiddenType::class)
            ->add('x2', HiddenType::class)
            ->add('y2', HiddenType::class)
            ->add('w', HiddenType::class)
            ->add('h', HiddenType::class)
            ->getForm();

        // Récupération des data du formulaire
        $form->handleRequest($request);
        $toReport = false;
        // Sur validation on généère la miniature croppée
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $toReport = true;
            $this->imageService->cropImage($image, $thumb, $data['x1'], $data['y1'], $data['w'], $data['h'], 150, 150);
        }

        return $this->render('upload\crop02.html.twig', [
            'useheader' => false,
            'usemenu' => false,
            'usesidebar' => false,
            'reportThumb' => $reportThumb,
            'image' => $path.'/'.$file,
            'thumb' => $path.'/thumb_'.$file,
            'form' => $form,
            'toReport' => $toReport,
        ]);
    }
}
