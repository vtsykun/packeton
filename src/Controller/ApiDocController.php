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

namespace Packeton\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Version;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ApiDocController extends AbstractController
{
    public function __construct(
        protected ManagerRegistry $registry
    ){}

    /**
     * @Route("/apidoc", name="api_doc")
     */
    public function indexAction()
    {
        $qb = $this->registry->getRepository(Version::class)
            ->createQueryBuilder('v');

        $qb->select(['v.name'])
            ->groupBy('v.name')
            ->orderBy('COUNT(v.id)', 'DESC')
            ->setMaxResults(1);

        try {
            $examplePackage = $qb->getQuery()->getSingleScalarResult();
        } catch (\Exception $exception) {
            $examplePackage = 'monolog/monolog';
        }

        return $this->render('apidoc/index.html.twig', [
            'examplePackage' => $examplePackage
        ]);
    }
}
