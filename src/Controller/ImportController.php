<?php

declare(strict_types=1);

namespace Packeton\Controller;

use Packeton\Form\Type\Package\ImportPackagesType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class ImportController extends AbstractController
{
    #[Route('/import', name: 'package_import')]
    #[IsGranted('ROLE_ADMIN')]
    public function importAction(Request $request)
    {
        $form = $this->createForm(ImportPackagesType::class);

        return $this->render('import/import.html.twig', ['form' => $form->createView()]);
    }

    #[Route('/import/check', name: 'package_import_check')]
    #[IsGranted('ROLE_ADMIN')]
    public function checkImport(Request $request)
    {
        $form = $this->createForm(ImportPackagesType::class);

        return $this->render('import/import.html.twig', ['form' => $form->createView()]);
    }
}
