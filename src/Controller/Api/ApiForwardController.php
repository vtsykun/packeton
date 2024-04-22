<?php

declare(strict_types=1);

namespace Packeton\Controller\Api;

use Packeton\Attribute\Vars;
use Packeton\Entity\Package;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api', name: 'api_', defaults: ['_format' => 'json'])]
class ApiForwardController extends AbstractController
{
    use ApiControllerTrait;

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

    #[Route('/packages/{name}/dependents', name: 'packages_dependents', requirements: ['name' => '([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?|ext-[A-Za-z0-9_.-]+?)'], methods: ['GET'])]
    public function dependents(string $name)
    {
        return $this->forward('Packeton\Controller\PackageController::dependentsAction', ['name' => $name, '_format' => 'json']);
    }

    #[Route('/packages/{name}/changelog', name: 'packages_changelog', requirements: ['name' => '%package_name_regex%'], methods: ['GET'])]
    public function changelog(#[Vars] Package $package, Request $request)
    {
        return $this->forward('Packeton\Controller\PackageController::changelogAction', ['package' => $package, '_format' => 'json'], $request->query->all());
    }

    #[Route('/jobs/{id}', name: 'job_result', methods: ['GET'])]
    public function jobResult(string $id)
    {
        return $this->forward('Packeton\Controller\Api\ApiController::getJobAction', ['id' => $id,]);
    }
}
