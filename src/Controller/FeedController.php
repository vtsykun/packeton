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
use Laminas\Feed\Writer\Entry;
use Laminas\Feed\Writer\Feed;
use Packeton\Entity\Package;
use Packeton\Entity\Version;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @author Rafael Dohms <rafael@doh.ms>
 *
 * @Route("/feeds")
 */
class FeedController extends AbstractController
{
    public function __construct(
        protected ManagerRegistry $registry
    ){}

    /**
     * @Route("/", name="feeds")
     */
    public function feedsAction()
    {
        return [];
    }

    /**
     * @Route(
     *     "/packages.{_format}",
     *     name="feed_packages",
     *     methods={"GET"},
     *     requirements={"_format"="(rss|atom)"}
     * )
     */
    public function packagesAction(Request $req)
    {
        $repo = $this->registry->getRepository(Package::class);
        $packages = $this->getLimitedResults(
            $repo->getQueryBuilderForNewestPackages()
        );

        $feed = $this->buildFeed(
            $req,
            'Newly Submitted Packages',
            'Latest packages submitted to Packagist.',
            $this->generateUrl('browse', [], UrlGeneratorInterface::ABSOLUTE_URL),
            $packages
        );

        return $this->buildResponse($req, $feed);
    }

    /**
     * @Route(
     *     "/releases.{_format}",
     *     name="feed_releases",
     *     methods={"GET"},
     *     requirements={"_format"="(rss|atom)"}
     * )
     */
    public function releasesAction(Request $req)
    {
        $repo = $this->registry->getRepository(Version::class);
        $packages = $this->getLimitedResults(
            $repo->getQueryBuilderForLatestVersionWithPackage()
        );

        $feed = $this->buildFeed(
            $req,
            'New Releases',
            'Latest releases of all packages.',
            $this->generateUrl('browse', [], UrlGeneratorInterface::ABSOLUTE_URL),
            $packages
        );

        return $this->buildResponse($req, $feed);
    }

    /**
     * @Route(
     *     "/vendor.{vendor}.{_format}",
     *     name="feed_vendor",
     *     methods={"GET"},
     *     requirements={"_format"="(rss|atom)", "vendor"="[A-Za-z0-9_.-]+"}
     * )
     */
    public function vendorAction(Request $req, $vendor)
    {
        $repo = $this->registry->getRepository(Version::class);
        $packages = $this->getLimitedResults(
            $repo->getQueryBuilderForLatestVersionWithPackage($vendor)
        );

        $feed = $this->buildFeed(
            $req,
            "$vendor packages",
            "Latest packages updated on Packagist of $vendor.",
            $this->generateUrl('view_vendor', ['vendor' => $vendor], UrlGeneratorInterface::ABSOLUTE_URL),
            $packages
        );

        return $this->buildResponse($req, $feed);
    }

    /**
     * @Route(
     *     "/package.{package}.{_format}",
     *     name="feed_package",
     *     methods={"GET"},
     *     requirements={"_format"="(rss|atom)", "package"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"}
     * )
     */
    public function packageAction(Request $req, $package)
    {
        $repo = $this->registry->getRepository(Version::class);
        $packages = $this->getLimitedResults(
            $repo->getQueryBuilderForLatestVersionWithPackage(null, $package)
        );

        $feed = $this->buildFeed(
            $req,
            "$package releases",
            "Latest releases on Packagist of $package.",
            $this->generateUrl('view_package', ['name' => $package], UrlGeneratorInterface::ABSOLUTE_URL),
            $packages
        );

        $response = $this->buildResponse($req, $feed);

        $first = reset($packages);
        if (false !== $first) {
            $response->setDate($first->getReleasedAt());
        }

        return $response;
    }

    /**
     * Limits a query to the desired number of results
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder
     *
     * @return array|\Traversable
     */
    protected function getLimitedResults(QueryBuilder $queryBuilder)
    {
        $query = $queryBuilder
            ->getQuery()
            ->setMaxResults(
                $this->getParameter('packagist_web.rss_max_items')
            );

        return $query->getResult();
    }

    /**
     * Builds the desired feed
     *
     * @param string $title
     * @param string $description
     * @param array  $items
     *
     * @return \Laminas\Feed\Writer\Feed
     */
    protected function buildFeed(Request $req, $title, $description, $url, $items)
    {
        $feed = new Feed();
        $feed->setTitle($title);
        $feed->setDescription($description);
        $feed->setLink($url);
        $feed->setGenerator('Packagist');

        foreach ($items as $item) {
            $entry = $feed->createEntry();
            $this->populateEntry($entry, $item);
            $feed->addEntry($entry);
        }

        if ($req->getRequestFormat() == 'atom') {
            $feed->setFeedLink(
                $req->getUri(),
                $req->getRequestFormat()
            );
        }

        if ($feed->count()) {
            $feed->setDateModified($feed->getEntry(0)->getDateModified());
        } else {
            $feed->setDateModified(new \DateTime());
        }

        return $feed;
    }

    /**
     * Receives either a Package or a Version and populates a feed entry.
     *
     * @param \Laminas\Feed\Writer\Entry $entry
     * @param Package|Version         $item
     */
    protected function populateEntry(Entry $entry, $item)
    {
        if ($item instanceof Package) {
            $this->populatePackageData($entry, $item);
        } elseif ($item instanceof Version) {
            $this->populatePackageData($entry, $item->getPackage());
            $this->populateVersionData($entry, $item);
        }
    }

    /**
     * Populates a feed entry with data coming from Package objects.
     *
     * @param \Laminas\Feed\Writer\Entry $entry
     * @param Package                 $package
     */
    protected function populatePackageData(Entry $entry, Package $package)
    {
        $entry->setTitle($package->getName());
        $entry->setLink(
            $this->generateUrl(
                'view_package',
                ['name' => $package->getName()],
                UrlGeneratorInterface::ABSOLUTE_URL
            )
        );
        $entry->setId($package->getName());

        $entry->setDateModified($package->getCreatedAt());
        $entry->setDateCreated($package->getCreatedAt());
        $entry->setDescription($package->getDescription() ?: ' ');
    }

    /**
     * Populates a feed entry with data coming from Version objects.
     *
     * @param \Laminas\Feed\Writer\Entry $entry
     * @param Version                 $version
     */
    protected function populateVersionData(Entry $entry, Version $version)
    {
        $entry->setTitle($entry->getTitle()." ({$version->getVersion()})");
        $entry->setId($entry->getId().' '.$version->getVersion());

        $entry->setDateModified($version->getReleasedAt());
        $entry->setDateCreated($version->getReleasedAt());

        foreach ($version->getAuthors() as $author) {
            /** @var $author \Packeton\Entity\Author */
            if ($author->getName()) {
                $entry->addAuthor([
                    'name' => $author->getName()
                ]);
            }
        }
    }

    /**
     * Creates a HTTP Response and exports feed
     *
     * {@inheritDoc}
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function buildResponse(Request $req, Feed $feed)
    {
        $content = $feed->export($req->getRequestFormat());

        $response = new Response($content, 200);
        $response->setSharedMaxAge(3600);

        return $response;
    }
}
