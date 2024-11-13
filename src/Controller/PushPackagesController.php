<?php

declare(strict_types=1);

namespace Packeton\Controller;

use Packeton\Form\Handler\PushPackageHandler;
use Packeton\Form\Type\Push\NexusPushType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(defaults: ['_format' => 'json'])]
class PushPackagesController extends AbstractController
{
    #[Route('/packages/upload/{name}/{version}', name: 'package_push_nexus', requirements: ['name' => '%package_name_regex%'], methods: ['PUT', 'POST'])]
    #[IsGranted('ROLE_MAINTAINER')]
    public function pushNexusAction(PushPackageHandler $handler, Request $request, string $name, string $version): Response
    {
        $form = $this->createApiForm(NexusPushType::class, options: ['method' => $request->getMethod()]);

        $handler($form, $request, $name, $version);

        return new JsonResponse([], 201);
    }

    protected function createApiForm(string $type, mixed $data = null, array $options = []): FormInterface
    {
        $options['csrf_protection'] = false;
        return $this->container->get('form.factory')->createNamed('', $type, $data, $options);
    }
}
