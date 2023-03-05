<?php

declare(strict_types=1);

namespace Packeton\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api', defaults: ['_format' => 'json'])]
class ApiGroupController extends AbstractController
{
}
