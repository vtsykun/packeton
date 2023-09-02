<?php

declare(strict_types=1);

namespace Packeton\Controller;

use Packeton\Form\Type\Package\ImportPackagesType;
use Packeton\Import\MassImportHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class ImportController extends AbstractController
{
    public function __construct(
        protected MassImportHandler $importHandler
    ){
    }

    #[Route('/import', name: 'package_import')]
    public function importAction(Request $request)
    {
        $form = $this->createForm(ImportPackagesType::class);

        return $this->render('import/import.html.twig', ['form' => $form->createView()]);
    }

    #[Route('/import/fetch-info', name: 'package_import_check')]
    public function fetchImportInfo(Request $request)
    {
        $form = $this->createForm(ImportPackagesType::class);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $import = $form->getData();
            $repos = $this->importHandler->getRepoUrls($import);
        }

        if ($form->isSubmitted()) {
            $errors = [];
            foreach ($form->getErrors(true) as $error) {
                $errors[] = $error->getMessage();
            }
            return new JsonResponse(['status' => 'error', 'reason' => $errors]);
        }

        return new JsonResponse(['status' => 'error', 'reason' => 'No data posted.']);
    }
}
