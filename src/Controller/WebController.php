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

use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Group;
use Packeton\Entity\Package;
use Packeton\Entity\Version;
use Packeton\Form\Model\SearchQuery;
use Packeton\Form\Type\SearchQueryType;
use Pagerfanta\Adapter\ArrayAdapter;
use Pagerfanta\Adapter\CallbackAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class WebController extends AbstractController
{
    public function __construct(
        protected ManagerRegistry $registry
    ){
    }

    #[Route('/', name: 'home')]
    public function indexAction(Request $request): Response
    {
        $page = $request->query->get('page', 1);
        $paginator = new Pagerfanta($this->createAdapter());
        $paginator->setMaxPerPage(10);
        $paginator->setCurrentPage($page);

        return $this->render('web/index.html.twig', [
            'packages' => $paginator
        ]);
    }

    /**
     * Rendered by views/Web/searchSection.html.twig
     */
    public function searchFormAction(Request $req)
    {
        $form = $this->createForm(SearchQueryType::class, new SearchQuery(), [
            'action' => $this->generateUrl('search.ajax'),
        ]);

        $filteredOrderBys = $this->getFilteredOrderedBys($req);

        $this->computeSearchQuery($req, $filteredOrderBys);

        $form->handleRequest($req);

        return $this->render('web/searchForm.html.twig', [
            'searchQuery' => $req->query->get('search_query')['query'] ?? '',
        ]);
    }

    #[Route('/search/', name: 'search.ajax')]
    #[Route('/search.{_format}', name: 'search', requirements: ['_format' => '(html|json)'], defaults: ['_format' => 'html'], methods: ['GET'])]
    public function searchAction(Request $req): Response
    {
        $packages = [];
        $form = $this->createForm(SearchQueryType::class, new SearchQuery());
        $form->handleRequest($req);
        if ($form->isSubmitted() && $form->isValid()) {
            $query = $form->getData()->getQuery();

            $perPage = $req->query->getInt('per_page', 15);
            if ($perPage <= 0 || $perPage > 100) {
                $perPage = max(0, min(100, $perPage));
            }

            $allowed = $this->isGranted('ROLE_FULL_CUSTOMER') ? null :
                $this->registry
                    ->getRepository(Group::class)
                    ->getAllowedPackagesForUser($this->getUser(), false);

            $page = $req->query->get('page', 1) - 1;
            $packages = $this->registry->getRepository(Package::class)
                ->searchPackage($query, $perPage, $page, $allowed);
        }

        $paginator = new Pagerfanta(new ArrayAdapter($packages));
        $paginator->setMaxPerPage(10);

        return $this->render('web/search.html.twig', ['packages' => $paginator]);
    }

    #[Route('/statistics', name: 'stats')]
    public function statsAction(\Redis $redis): Response
    {
        $packages = $this->registry->getRepository(Package::class)
            ->getPackagesStatisticsByMonthAndYear();

        $versions = $this->registry->getRepository(Version::class)
            ->getVersionStatisticsByMonthAndYear();

        $chart = ['versions' => [], 'packages' => [], 'months' => []];

        // prepare x axis
        if (isset($packages[0])) {
            $date = new \DateTime($packages[0]['year'] . '-' . $packages[0]['month'] . '-01');
        } else {
            $date = new \DateTime;
        }

        $now = new \DateTime;
        while ($date < $now) {
            $chart['months'][] = $month = $date->format('Y-m');
            $date->modify('+1month');
        }

        // prepare data
        $count = 0;
        foreach ($packages as $dataPoint) {
            $count += $dataPoint['pcount'];
            $chart['packages'][$dataPoint['year'] . '-' . str_pad($dataPoint['month'], 2, '0', STR_PAD_LEFT)] = $count;
        }

        $count = 0;
        foreach ($versions as $dataPoint) {
            $yearMonth = $dataPoint['year'] . '-' . str_pad($dataPoint['month'], 2, '0', STR_PAD_LEFT);
            $count += $dataPoint['vcount'];
            if (in_array($yearMonth, $chart['months'])) {
                $chart['versions'][$yearMonth] = $count;
            }
        }

        // fill gaps at the end of the chart
        if (count($chart['months']) > count($chart['packages'])) {
            $chart['packages'] += array_fill(0, count($chart['months']) - count($chart['packages']), !empty($chart['packages']) ? max($chart['packages']) : 0);
        }
        if (count($chart['months']) > count($chart['versions'])) {
            $chart['versions'] += array_fill(0, count($chart['months']) - count($chart['versions']), !empty($chart['versions']) ? max($chart['versions']) : 0);
        }

        $downloadsStartDate = '2017-04-13';

        $downloads = $redis->get('downloads') ?: 0;

        $date = new \DateTime($downloadsStartDate.' 00:00:00');
        $yesterday = new \DateTime('-2days 00:00:00');
        $dailyGraphStart = new \DateTime('-32days 00:00:00'); // 30 days before yesterday

        $dlChart = $dlChartMonthly = [];
        while ($date <= $yesterday) {
            if ($date > $dailyGraphStart) {
                $dlChart[$date->format('Y-m-d')] = 'downloads:'.$date->format('Ymd');
            }
            $dlChartMonthly[$date->format('Y-m')] = 'downloads:'.$date->format('Ym');
            $date->modify('+1day');
        }

        $dlChart = [
            'labels' => array_keys($dlChart),
            'values' => $redis->mget(array_values($dlChart))
        ];
        $dlChartMonthly = [
            'labels' => array_keys($dlChartMonthly),
            'values' => $redis->mget(array_values($dlChartMonthly))
        ];


        return $this->render('web/stats.html.twig', [
            'chart' => $chart,
            'packages' => !empty($chart['packages']) ? max($chart['packages']) : 0,
            'versions' => !empty($chart['versions']) ? max($chart['versions']) : 0,
            'downloads' => $downloads,
            'downloadsChart' => $dlChart,
            'maxDailyDownloads' => !empty($dlChart) ? max($dlChart['values']) : null,
            'downloadsChartMonthly' => $dlChartMonthly,
            'maxMonthlyDownloads' => !empty($dlChartMonthly) ? max($dlChartMonthly['values']) : null,
            'downloadsStartDate' => $downloadsStartDate,
        ]);
    }

    /**
     * @param Request $req
     *
     * @return array
     */
    protected function getFilteredOrderedBys(Request $req)
    {
        $orderBys = $req->query->get('orderBys', []);
        if (!$orderBys) {
            $orderBys = $req->query->get('search_query');
            $orderBys = $orderBys['orderBys'] ?? [];
        }

        if ($orderBys) {
            $allowedSorts = [
                'downloads' => 1,
                'favers' => 1
            ];

            $allowedOrders = [
                'asc' => 1,
                'desc' => 1,
            ];

            $filteredOrderBys = [];

            foreach ($orderBys as $orderBy) {
                if (isset($orderBy['sort'])
                    && isset($allowedSorts[$orderBy['sort']])
                    && isset($orderBy['order'])
                    && isset($allowedOrders[$orderBy['order']])) {
                    $filteredOrderBys[] = $orderBy;
                }
            }
        } else {
            $filteredOrderBys = [];
        }

        return $filteredOrderBys;
    }

    /**
     * @param array $orderBys
     *
     * @return array
     */
    protected function getNormalizedOrderBys(array $orderBys)
    {
        $normalizedOrderBys = [];

        foreach ($orderBys as $sort) {
            $normalizedOrderBys[$sort['sort']] = $sort['order'];
        }

        return $normalizedOrderBys;
    }

    /**
     * @param Request $req
     * @param array $normalizedOrderBys
     *
     * @return array
     */
    protected function getOrderBysViewModel(Request $req, array $normalizedOrderBys)
    {
        $makeDefaultArrow = function ($sort) use ($normalizedOrderBys) {
            if (isset($normalizedOrderBys[$sort])) {
                if (strtolower($normalizedOrderBys[$sort]) === 'asc') {
                    $val = 'glyphicon-arrow-up';
                } else {
                    $val = 'glyphicon-arrow-down';
                }
            } else {
                $val = '';
            }

            return $val;
        };

        $makeDefaultHref = function ($sort) use ($req, $normalizedOrderBys) {
            if (isset($normalizedOrderBys[$sort])) {
                if (strtolower($normalizedOrderBys[$sort]) === 'asc') {
                    $order = 'desc';
                } else {
                    $order = 'asc';
                }
            } else {
                $order = 'desc';
            }

            $query = $req->query->get('search_query');
            $query = $query['query'] ?? '';

            return '?' . http_build_query([
                'q' => $query,
                'orderBys' => [
                    [
                        'sort' => $sort,
                        'order' => $order
                    ]
                ]
            ]);
        };

        return [
            'downloads' => [
                'title' => 'Sort by downloads',
                'class' => 'glyphicon-arrow-down',
                'arrowClass' => $makeDefaultArrow('downloads'),
                'href' => $makeDefaultHref('downloads')
            ],
            'favers' => [
                'title' => 'Sort by favorites',
                'class' => 'glyphicon-star',
                'arrowClass' => $makeDefaultArrow('favers'),
                'href' => $makeDefaultHref('favers')
            ],
        ];
    }

    /**
     * @param Request $req
     * @param array $filteredOrderBys
     */
    private function computeSearchQuery(Request $req, array $filteredOrderBys)
    {
        // transform q=search shortcut
        if ($req->query->has('q') || $req->query->has('orderBys')) {
            $searchQuery = [];

            $q = $req->query->get('q');

            if ($q !== null) {
                $searchQuery['query'] = $q;
            }

            if (!empty($filteredOrderBys)) {
                $searchQuery['orderBys'] = $filteredOrderBys;
            }

            $req->query->set(
                'search_query',
                $searchQuery
            );
        }
    }

    private function createAdapter()
    {
        /** @var QueryBuilder $qb */
        $qb = $this->registry->getManager()->createQueryBuilder();
        $qb->from(Package::class, 'p');
        $repo = $this->registry->getRepository(Package::class);
        if (!$this->isGranted('ROLE_FULL_CUSTOMER')) {
            $packages = $this->registry->getRepository(Group::class)
                ->getAllowedPackagesForUser($this->getUser());
            $qb->andWhere('p.id IN (:ids)')
                ->setParameter('ids', $packages);
        }

        $adapter = new CallbackAdapter(
            function () use ($qb) {
                $qb = clone $qb;
                $qb->select('COUNT(1)');
                return $qb->getQuery()->getSingleScalarResult();
            },
            function ($offset, $length) use ($qb, $repo) {
                $qb = clone $qb;
                $qb->select('p')
                    ->setMaxResults($length)
                    ->setFirstResult($offset)
                    ->orderBy('p.id', 'DESC');

                $packages = array_map(
                    function (Package $package) use ($repo) {
                        return [
                            'package' => $package,
                            'dependencies' => $repo->getDependents($package->getName())
                        ];
                    },
                    $qb->getQuery()->getResult()
                );

                return $packages;
            }
        );

        return $adapter;
    }
}
