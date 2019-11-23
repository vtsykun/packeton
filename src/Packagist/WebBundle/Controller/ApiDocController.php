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

namespace Packagist\WebBundle\Controller;

use Packagist\WebBundle\Entity\Version;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be> 
 */
class ApiDocController extends Controller
{
    /**
     * @Template()
     * @Route("/apidoc", name="api_doc")
     */
    public function indexAction()
    {
        $qb = $this->getDoctrine()->getRepository(Version::class)
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

        return [
            'examplePackage' => $examplePackage
        ];
    }
}
