<?php

declare(strict_types=1);

namespace Packeton\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api', name: 'api_', defaults: ['_format' => 'json'])]
class ApiForwardController extends AbstractController
{
    #[Route('/packages', name: 'packages_lists', methods: ['GET'])]
    public function lists(Request $request)
    {
        return $this->forward('Packeton\Controller\PackageController::listAction', [], $request->query->all());
    }

    #[Route('/packages/{name}', name: 'packages_item', requirements: ['name' => '%package_name_regex%'], methods: ['GET'])]
    public function item(string $name)
    {
        return $this->forward('Packeton\Controller\PackageController::viewPackageAction', ['name' => $name, '_format' => 'json']);
    }

    #[Route('/jobs/{id}', name: 'job_result', methods: ['GET'])]
    public function jobResult(string $id)
    {
        return $this->forward('Packeton\Controller\Api\ApiController::getJobAction', ['id' => $id,]);
    }
}
