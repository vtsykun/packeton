<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Packeton\Controller\Api;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Version;
use Packeton\Service\SwaggerDumper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ApiDocController extends AbstractController
{
    public function __construct(
        protected ManagerRegistry $registry,
        protected SwaggerDumper $swagger,
    ){
    }

    #[Route('/apidoc.{_format}', name: 'api_doc', defaults: ['_format' => 'html'])]
    public function indexAction(Request $req): Response
    {
        $qb = $this->registry->getRepository(Version::class)
            ->createQueryBuilder('v');

        $qb->select(['v.name'])
            ->groupBy('v.name')
            ->orderBy('COUNT(v.id)', 'DESC')
            ->setMaxResults(1);

        try {
            $examplePackage = $qb->getQuery()->getSingleScalarResult();
        } catch (\Exception) {
            $examplePackage = 'monolog/monolog';
        }

        $OAS = $this->swagger->dump(['{{ package }}' => $examplePackage]);
        if ($req->getRequestFormat() === 'json') {
            return new JsonResponse($OAS);
        }

        return $this->render('apidoc/index.html.twig', [
            'examplePackage' => $examplePackage,
            'swagger_data' => $OAS,
        ]);
    }
}
