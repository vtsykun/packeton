<?php

namespace Packeton\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Attribute\Vars;
use Packeton\Entity\User;
use Packeton\Model\DownloadManager;
use Packeton\Model\FavoriteManager;
use Packeton\Model\PackageManager;
use Packeton\Model\ProviderManager;
use Packeton\Service\Scheduler;
use Packeton\Util\ChangelogUtils;
use Doctrine\ORM\NoResultException;
use Packeton\Repository\PackageRepository;
use Packeton\Repository\VersionRepository;
use Packeton\Form\Model\MaintainerRequest;
use Packeton\Form\Type\AbandonedType;
use Packeton\Entity\Package;
use Packeton\Entity\Version;
use Packeton\Form\Type\AddMaintainerRequestType;
use Packeton\Form\Type\PackageType;
use Packeton\Form\Type\RemoveMaintainerRequestType;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Routing\Annotation\Route;
use Composer\Package\Version\VersionParser;
use Pagerfanta\Adapter\FixedAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class PackageController extends AbstractController
{
    use ControllerTrait;

    public function __construct(
        protected ManagerRegistry $registry,
        protected DownloadManager $downloadManager,
        protected FavoriteManager $favoriteManager,
        protected ProviderManager $providerManager,
        protected LoggerInterface $logger,
    ){}

    /**
     * @Route("/packages/", name="all_packages")
     */
    public function allAction(Request $req)
    {
        if (!$this->isGranted('ROLE_MAINTAINER')) {
            throw new AccessDeniedException;
        }

        return new RedirectResponse($this->generateUrl('browse'), Response::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * @Route("/packages/list.json", name="list", defaults={"_format"="json"}, methods={"GET"})
     */
    public function listAction(Request $req)
    {
        if (!$this->isGranted('ROLE_FULL_CUSTOMER')) {
            throw new AccessDeniedException;
        }

        /** @var PackageRepository $repo */
        $repo = $this->registry->getRepository(Package::class);
        $fields = (array) $req->query->get('fields', []);

        if ($fields) {
            $baseFields = array_intersect($fields, ['repository', 'type']);

            $filters = array_filter([
                'type' => $req->query->get('type'),
                'vendor' => $req->query->get('vendor'),
            ]);

            $packages = $repo->getPackagesWithFields($filters, $baseFields);
            if ($fields !== $baseFields) {
                $versionRepo = $this->registry->getRepository(Version::class);
                foreach ($packages as $name => $packageData) {
                    $package = $repo->findOneBy(['name' => $name]);
                    $metadata = $package->toArray($versionRepo);

                    foreach ($fields as $field) {
                        $packageData[$field] = $metadata[$field] ?? null;
                    }

                    $packages[$name] = $packageData;
                }
            }

            return new JsonResponse(['packages' => $packages]);
        }

        if ($req->query->get('type')) {
            $names = $repo->getPackageNamesByType($req->query->get('type'));
        } elseif ($req->query->get('vendor')) {
            $names = $repo->getPackageNamesByVendor($req->query->get('vendor'));
        } else {
            $names = $this->providerManager->getPackageNames();
        }

        return new JsonResponse(['packageNames' => $names]);
    }

    /**
     * @Route("/packages/submit", name="submit")
     */
    public function submitPackageAction(Request $req)
    {
        if (!$this->isGranted('ROLE_MAINTAINER')) {
            throw new AccessDeniedException();
        }

        $package = new Package();
        $form = $this->createForm(PackageType::class, $package, [
            'action' => $this->generateUrl('submit'),
            'validation_groups' => ['Create']
        ]);

        if ($this->getUser() instanceof User) {
            $package->addMaintainer($this->getUser());
        }

        $form->handleRequest($req);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $em = $this->registry->getManager();
                $em->persist($package);
                $em->flush();

                $this->providerManager->insertPackage($package);
                $this->addFlash('success', $package->getName().' has been added to the package list, the repository will now be crawled.');

                return new RedirectResponse($this->generateUrl('view_package', ['name' => $package->getName()]));
            } catch (\Exception $e) {
                $this->logger->critical($e->getMessage(), ['exception', $e]);
                $this->addFlash('error', $package->getName().' could not be saved.');
            }
        }

        return $this->render(
            'package/submitPackage.html.twig',
            ['form' => $form->createView(), 'page' => 'submit']
        );
    }

    /**
     * @Route("/packages/fetch-info", name="submit.fetch_info", defaults={"_format"="json"})
     * {@inheritdoc}
     */
    public function fetchInfoAction(Request $req)
    {
        if (!$this->isGranted('ROLE_MAINTAINER')) {
            throw new AccessDeniedException;
        }

        $package = new Package;
        $form = $this->createForm(PackageType::class, $package, ['validation_groups' => ['Create']]);

        if ($this->getUser() instanceof User) {
            $package->addMaintainer($this->getUser());
        }

        $form->handleRequest($req);
        if ($form->isSubmitted() && $form->isValid()) {
            list(, $name) = explode('/', $package->getName(), 2);

            $existingPackages = $this->getEM()
                ->getConnection()
                ->fetchAllAssociative(
                    'SELECT name FROM package WHERE name LIKE :query',
                    ['query' => '%/'.$name]
                );

            $similar = [];

            foreach ($existingPackages as $existingPackage) {
                $similar[] = [
                    'name' => $existingPackage['name'],
                    'url' => $this->generateUrl('view_package', ['name' => $existingPackage['name']], true),
                ];
            }

            return new JsonResponse(['status' => 'success', 'name' => $package->getName(), 'similar' => $similar]);
        }

        if ($form->isSubmitted()) {
            $errors = [];
            if (count($form->getErrors())) {
                foreach ($form->getErrors() as $error) {
                    $errors[] = $error->getMessage();
                }
            }
            foreach ($form->all() as $child) {
                if (count($child->getErrors())) {
                    foreach ($child->getErrors() as $error) {
                        $errors[] = $error->getMessage();
                    }
                }
            }

            return new JsonResponse(['status' => 'error', 'reason' => $errors]);
        }

        return new JsonResponse(['status' => 'error', 'reason' => 'No data posted.']);
    }

    /**
     * @Route("/packages/{vendor}/", name="view_vendor", requirements={"vendor"="[A-Za-z0-9_.-]+"})
     */
    public function viewVendorAction($vendor)
    {
        if (!$this->isGranted('ROLE_MAINTAINER')) {
            throw new AccessDeniedException;
        }

        $packages = $this->registry
            ->getRepository(Package::class)
            ->getFilteredQueryBuilder(['vendor' => $vendor.'/%'], true)
            ->getQuery()
            ->getResult();

        if (!$packages) {
            return $this->redirect($this->generateUrl('search', ['q' => $vendor, 'reason' => 'vendor_not_found']));
        }

        return $this->render('package/viewVendor.html.twig', [
            'packages' => $packages,
            'meta' => $this->getPackagesMetadata($packages),
            'vendor' => $vendor,
            'paginate' => false,
        ]);
    }

    /**
     * @Route(
     *     "/providers/{name}",
     *     name="view_providers",
     *     requirements={"name"="[A-Za-z0-9/_.-]+?"},
     *     defaults={"_format"="html"},
     *     methods={"GET"}
     * )
     */
    public function viewProvidersAction($name, \Redis $redis)
    {
        if (!$this->isGranted('ROLE_MAINTAINER')) {
            throw new AccessDeniedException;
        }

        /** @var PackageRepository $repo */
        $repo = $this->registry->getRepository(Package::class);
        $providers = $repo->findProviders($name);
        if (!$providers) {
            return $this->redirect($this->generateUrl('search', ['q' => $name, 'reason' => 'package_not_found']));
        }

        $package = $repo->findOneBy(['name' => $name]);
        if ($package) {
            $providers[] = $package;
        }

        $trendiness = [];
        foreach ($providers as $package) {
            /** @var Package $package */
            $trendiness[$package->getId()] = (int) $redis->zscore('downloads:trending', $package->getId());
        }
        usort($providers, function (Package $a, Package $b) use ($trendiness) {
            if ($trendiness[$a->getId()] === $trendiness[$b->getId()]) {
                return strcmp($a->getName(), $b->getName());
            }
            return $trendiness[$a->getId()] > $trendiness[$b->getId()] ? -1 : 1;
        });

        return $this->render('package/providers.html.twig', [
            'name' => $name,
            'packages' => $providers,
            'meta' => $this->getPackagesMetadata($providers),
            'paginate' => false,
        ]);
    }

    /**
     * @Route(
     *     "/packages/{name}.{_format}",
     *     name="view_package",
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "_format"="(json)"},
     *     defaults={"_format"="html"},
     *     methods={"GET"}
     * )
     */
    public function viewPackageAction(Request $req, $name, CsrfTokenManagerInterface $csrfTokenManager)
    {
        if (preg_match('{^(?P<pkg>ext-[a-z0-9_.-]+?)/(?P<method>dependents|suggesters)$}i', $name, $match)) {
            if (!$this->isGranted('ROLE_FULL_CUSTOMER')) {
                throw new AccessDeniedHttpException;
            }
            return $this->{$match['method'].'Action'}($req, $match['pkg']);
        }

        /** @var PackageRepository $repo */
        $repo = $this->registry->getRepository(Package::class);

        try {
            /** @var Package $package */
            $package = $repo->getPartialPackageByNameWithVersions($name);
        } catch (NoResultException $e) {
            throw new NotFoundHttpException;
        }

        if (!$this->isGranted('ROLE_FULL_CUSTOMER', $package)) {
            throw new NotFoundHttpException;
        }

        if ('json' === $req->getRequestFormat()) {
            $data = $package->toArray($this->registry->getRepository(Version::class));
            $data['dependents'] = $repo->getDependentCount($package->getName());
            $data['suggesters'] = $repo->getSuggestCount($package->getName());

            $data['downloads'] = $this->downloadManager->getDownloads($package);
            $data['favers'] = $this->favoriteManager->getFaverCount($package);

            if (empty($data['versions'])) {
                $data['versions'] = [];
            }

            $response = new JsonResponse(['package' => $data]);
            $response->setSharedMaxAge(12*3600);

            return $response;
        }

        $version = null;
        $expandedVersion = null;
        $versions = $package->getVersions();
        if (is_object($versions)) {
            $versions = $versions->toArray();
        }

        usort($versions, Package::class.'::sortVersions');

        if (count($versions)) {
            /** @var VersionRepository $versionRepo */
            $versionRepo = $this->registry->getRepository(Version::class);
            $this->getEM()->refresh(reset($versions));
            $version = $versionRepo->getFullVersion(reset($versions)->getId());

            $expandedVersion = reset($versions);
            foreach ($versions as $v) {
                if (!$v->isDevelopment()) {
                    $expandedVersion = $v;
                    break;
                }
            }

            $this->registry->getManager()->refresh($expandedVersion);
            $expandedVersion = $versionRepo->getFullVersion($expandedVersion->getId());
        }

        $data = [
            'package' => $package,
            'version' => $version,
            'versions' => $versions,
            'expandedVersion' => $expandedVersion,
        ];

        $data['downloads'] = $this->downloadManager->getDownloads($package);
        if ($this->getUser()) {
            $data['is_favorite'] = $this->favoriteManager->isMarked($this->getUser(), $package);
        }

        $data['dependents'] = $repo->getDependentCount($package->getName());
        $data['suggesters'] = $repo->getSuggestCount($package->getName());

        if ($maintainerForm = $this->createAddMaintainerForm($package)) {
            $data['addMaintainerForm'] = $maintainerForm->createView();
        }
        if ($removeMaintainerForm = $this->createRemoveMaintainerForm($package)) {
            $data['removeMaintainerForm'] = $removeMaintainerForm->createView();
        }
        if ($deleteForm = $this->createDeletePackageForm($package)) {
            $data['deleteForm'] = $deleteForm->createView();
        }

        if ($this->getUser() && (
                $this->isGranted('ROLE_DELETE_PACKAGES')
                || $package->getMaintainers()->contains($this->getUser())
            )) {
            $data['deleteVersionCsrfToken'] = $csrfTokenManager->getToken('delete_version');
        }

        return $this->render('package/viewPackage.html.twig', $data);
    }

    /**
     * @Route(
     *     "/packages/{package}/changelog",
     *     requirements={"package"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"},
     *     name="package_changelog",
     *     methods={"GET"}
     * )
     *
     * @param string $package
     * @param Request $request
     * @return Response
     */
    public function changelogAction($package, Request $request)
    {
        $package = $this->registry->getRepository(Package::class)
            ->findOneBy(['name' => $package]);
        if (null === $package) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }
        if (!$this->isGranted('ROLE_MAINTAINER', $package)) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        $changelogBuilder = $this->container->get(ChangelogUtils::class);
        $fromVersion = $request->get('from');
        $toVersion = $request->get('to');
        if (!$toVersion) {
            return new JsonResponse(['error' => 'Parameters: "to" can not be empty'], 400);
        }

        if (!$fromVersion) {
            $fromVersion = $this->registry
                ->getRepository(Version::class)
                ->getPreviousRelease($package->getName(), $toVersion);
            if (!$fromVersion) {
                return new JsonResponse(['error' => 'Previous release do not exists'], 400);
            }
        }

        $changeLog = $changelogBuilder->getChangelog($package, $fromVersion, $toVersion);
        return new JsonResponse(
            [
                'result' => $changeLog,
                'error' => null,
                'metadata' => [
                    'from' => $fromVersion,
                    'to' => $toVersion,
                    'package' => $package->getName()
                ]
            ]
        );
    }

    /**
     * @Route(
     *     "/packages/{name}/downloads.{_format}",
     *     name="package_downloads_full",
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "_format"="(json)"},
     *     methods={"GET"}
     * )
     */
    public function viewPackageDownloadsAction(Request $req, $name)
    {
        if (!$this->isGranted('ROLE_MAINTAINER')) {
            throw new AccessDeniedHttpException;
        }

        /** @var PackageRepository $repo */
        $repo = $this->registry->getRepository(Package::class);

        try {
            /** @var $package Package */
            $package = $repo->getPartialPackageByNameWithVersions($name);
        } catch (NoResultException $e) {
            if ('json' === $req->getRequestFormat()) {
                return new JsonResponse(['status' => 'error', 'message' => 'Package not found'], 404);
            }

            if ($providers = $repo->findProviders($name)) {
                return $this->redirect($this->generateUrl('view_providers', ['name' => $name]));
            }

            return $this->redirect($this->generateUrl('search', ['q' => $name, 'reason' => 'package_not_found']));
        }

        $versions = $package->getVersions();
        $data = [
            'name' => $package->getName(),
        ];

        $data['downloads']['total'] = $this->downloadManager->getDownloads($package);
        $data['favers'] = $this->favoriteManager->getFaverCount($package);

        foreach ($versions as $version) {
            $data['downloads']['versions'][$version->getVersion()] = $this->downloadManager->getDownloads($package, $version);
        }

        $response = new Response(json_encode(['package' => $data]), 200);
        $response->setSharedMaxAge(3600);

        return $response;
    }

    /**
     * @Route(
     *     "/versions/{versionId}.{_format}",
     *     name="view_version",
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "versionId"="[0-9]+", "_format"="(json)"},
     *     methods={"GET"}
     * )
     */
    public function viewPackageVersionAction(Request $req, $versionId)
    {
        /** @var VersionRepository $repo  */
        $repo = $this->registry->getRepository(Version::class);
        if (!$this->isGranted('ROLE_FULL_CUSTOMER', $repo->find($versionId))) {
            throw new AccessDeniedHttpException;
        }

        $html = $this->renderView(
            'package/versionDetails.html.twig',
            ['version' => $repo->getFullVersion($versionId)]
        );

        return new JsonResponse(['content' => $html]);
    }

    /**
     * @Route(
     *     "/versions/{versionId}/delete",
     *     name="delete_version",
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "versionId"="[0-9]+"},
     *     methods={"DELETE"}
     * )
     *
     * {@inheritdoc}
     */
    public function deletePackageVersionAction(Request $req, $versionId)
    {
        if (!$this->isGranted('ROLE_MAINTAINER')) {
            throw new AccessDeniedException;
        }

        /** @var VersionRepository $repo  */
        $repo = $this->registry->getRepository(Version::class);

        /** @var Version $version  */
        $version = $repo->getFullVersion($versionId);
        $package = $version->getPackage();

        if (!$package->getMaintainers()->contains($this->getUser()) && !$this->isGranted('ROLE_DELETE_PACKAGES')) {
            throw new AccessDeniedException;
        }

        if (!$this->isCsrfTokenValid('delete_version', $req->request->get('_token'))) {
            throw new AccessDeniedException;
        }

        $repo->remove($version);
        $this->registry->getManager()->flush();
        $this->registry->getManager()->clear();

        return new Response('', 204);
    }

    /**
     * @Route("/packages/{name}", name="update_package", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"}, defaults={"_format" = "json"}, methods={"PUT"})
     * @inheritDoc
     */
    public function updatePackageAction(Request $req, $name)
    {
        $doctrine = $this->registry;

        try {
            /** @var Package $package */
            $package = $doctrine
                ->getRepository(Package::class)
                ->getPackageByName($name);
        } catch (NoResultException) {
            return new JsonResponse(['status' => 'error', 'message' => 'Package not found'], 404);
        }

        if ($package->isAbandoned() && $package->getReplacementPackage() === 'spam/spam') {
            throw new NotFoundHttpException('This is a spam package');
        }

        $username = $req->request->has('username') ?
            $req->request->get('username') :
            $req->query->get('username');

        $apiToken = $req->request->has('apiToken') ?
            $req->request->get('apiToken') :
            $req->query->get('apiToken');

        $update = $req->request->get('update', $req->query->get('update'));
        $autoUpdated = $req->request->get('autoUpdated', $req->query->get('autoUpdated'));
        $updateEqualRefs = $req->request->get('updateAll', $req->query->get('updateAll'));

        $user = $this->getUser() ?: $doctrine
            ->getRepository(User::class)
            ->findOneBy(['username' => $username, 'apiToken' => $apiToken]);

        if (!$user) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid credentials'], 403);
        }

        if ($package->getMaintainers()->contains($user) || $this->isGranted('ROLE_UPDATE_PACKAGES')) {
            if (null !== $autoUpdated) {
                $package->setAutoUpdated((bool) $autoUpdated);
                $doctrine->getManager()->flush();
            }

            if ($update) {
                $job = $this->container->get(Scheduler::class)->scheduleUpdate($package, $updateEqualRefs);

                return new JsonResponse(['status' => 'success', 'job' => $job->getId()], 202);
            }

            return new JsonResponse(['status' => 'success'], 202);
        }

        return new JsonResponse(['status' => 'error', 'message' => 'Could not find a package that matches this request (does user maintain the package?)',], 404);
    }

    /**
     * @Route("/packages/{name}", name="delete_package", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"}, methods={"DELETE"})
     */
    public function deletePackageAction(Request $req, $name)
    {
        if (!$this->isGranted('ROLE_MAINTAINER')) {
            throw new AccessDeniedException;
        }

        $doctrine = $this->registry;

        try {
            /** @var Package $package */
            $package = $doctrine
                ->getRepository(Package::class)
                ->getPartialPackageByNameWithVersions($name);
        } catch (NoResultException $e) {
            throw new NotFoundHttpException('The requested package, '.$name.', was not found.');
        }

        if (!$form = $this->createDeletePackageForm($package)) {
            throw new AccessDeniedException;
        }
        $form->submit($req->request->get('form'));
        if ($form->isValid()) {
            $req->getSession()->save();

            $this->container->get(PackageManager::class)->deletePackage($package);

            return new Response('', 204);
        }

        return new Response('Invalid form input', 400);
    }

    /**
     * @Route("/packages/{name}/maintainers", name="add_maintainer", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"})
     */
    public function createMaintainerAction(Request $req, $name)
    {
        if (!$this->isGranted('ROLE_MAINTAINER')) {
            throw new AccessDeniedException;
        }

        /** @var $package Package */
        $package = $this->registry
            ->getRepository(Package::class)
            ->findOneByName($name);

        if (!$package) {
            throw new NotFoundHttpException('The requested package, '.$name.', was not found.');
        }

        if (!$form = $this->createAddMaintainerForm($package)) {
            throw new AccessDeniedException('You must be a package\'s maintainer to modify maintainers.');
        }

        $data = [
            'package' => $package,
            'versions' => null,
            'expandedVersion' => null,
            'version' => null,
            'addMaintainerForm' => $form->createView(),
            'show_add_maintainer_form' => true,
        ];

        $form->handleRequest($req);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $em = $this->registry->getManager();
                $user = $form->getData()->getUser();

                if (!empty($user)) {
                    if (!$package->getMaintainers()->contains($user)) {
                        $package->addMaintainer($user);
                    }

                    $em->persist($package);
                    $em->flush();

                    $this->addFlash('success', $user->getUsername().' is now a '.$package->getName().' maintainer.');

                    return new RedirectResponse($this->generateUrl('view_package', ['name' => $package->getName()]));
                }

                $this->addFlash('error', 'The user could not be found.');
            } catch (\Exception $e) {
                $this->logger->critical($e->getMessage(), ['exception' => $e]);
                $this->addFlash('error', 'The maintainer could not be added.');
            }
        }

        return $this->render('package/viewPackage.html.twig', $data);
    }

    /**
     * @Route("/packages/{name}/maintainers/delete", name="remove_maintainer", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"})
     */
    public function removeMaintainerAction(Request $req, $name)
    {
        if (!$this->isGranted('ROLE_MAINTAINER')) {
            throw new AccessDeniedException;
        }

        /** @var $package Package */
        $package = $this->registry
            ->getRepository(Package::class)
            ->findOneByName($name);

        if (!$package) {
            throw new NotFoundHttpException('The requested package, '.$name.', was not found.');
        }
        if (!$removeMaintainerForm = $this->createRemoveMaintainerForm($package)) {
            throw new AccessDeniedException('You must be a package\'s maintainer to modify maintainers.');
        }

        $data = [
            'package' => $package,
            'versions' => null,
            'expandedVersion' => null,
            'version' => null,
            'removeMaintainerForm' => $removeMaintainerForm->createView(),
            'show_remove_maintainer_form' => true,
        ];

        $removeMaintainerForm->handleRequest($req);
        if ($removeMaintainerForm->isSubmitted() && $removeMaintainerForm->isValid()) {
            try {
                $em = $this->registry->getManager();
                $user = $removeMaintainerForm->getData()->getUser();

                if (!empty($user)) {
                    if ($package->getMaintainers()->contains($user)) {
                        $package->getMaintainers()->removeElement($user);
                    }

                    $em->persist($package);
                    $em->flush();

                    $this->addFlash('success', $user->getUsername().' is no longer a '.$package->getName().' maintainer.');

                    return new RedirectResponse($this->generateUrl('view_package', ['name' => $package->getName()]));
                }
                $this->addFlash('error', 'The user could not be found.');
            } catch (\Exception $e) {
                $this->logger->critical($e->getMessage(), ['exception' => $e]);
                $this->addFlash('error', 'The maintainer could not be removed.');
            }
        }

        return $this->render('package/viewPackage.html.twig', $data);
    }

    /**
     * @Route(
     *     "/packages/{name}/edit",
     *     name="edit_package",
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"}
     * )
     */
    public function editAction(Request $req, #[Vars('name')] Package $package)
    {
        if (!$package->getMaintainers()->contains($this->getUser()) && !$this->isGranted('ROLE_EDIT_PACKAGES')) {
            throw new AccessDeniedException;
        }

        $form = $this->createForm(PackageType::class, $package, [
            'action' => $this->generateUrl('edit_package', ['name' => $package->getName()]),
            'validation_groups' => ['Update'],
        ]);

        $form->handleRequest($req);
        if ($form->isSubmitted() && $form->isValid()) {
            // Force updating of packages once the package is viewed after the redirect.
            $package->setCrawledAt(null);

            $em = $this->registry->getManager();
            $em->persist($package);
            $em->flush();

            $this->addFlash("success", "Changes saved.");

            return $this->redirect(
                $this->generateUrl("view_package", ["name" => $package->getName()])
            );
        }

        return $this->render(
            'package/edit.html.twig',
            [
                "package" => $package,
                "form" => $form->createView()
            ]
        );
    }

    /**
     * @Route(
     *      "/packages/{name}/abandon",
     *      name="abandon_package",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"}
     * )
     */
    public function abandonAction(Request $request, #[Vars] Package $package)
    {
        if (!$package->getMaintainers()->contains($this->getUser()) && !$this->isGranted('ROLE_EDIT_PACKAGES')) {
            throw new AccessDeniedException;
        }

        $form = $this->createForm(AbandonedType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $package->setAbandoned(true);
            $package->setReplacementPackage(str_replace('https://packagist.org/packages/', '', $form->get('replacement')->getData()));
            $package->setIndexedAt(null);
            $package->setCrawledAt(new \DateTime());
            $package->setUpdatedAt(new \DateTime());

            $em = $this->registry->getManager();
            $em->flush();

            return $this->redirect($this->generateUrl('view_package', ['name' => $package->getName()]));
        }

        return $this->render('package/abandon.html.twig', [
            'package' => $package,
            'form'    => $form->createView()
        ]);
    }

    /**
     * @Route(
     *      "/packages/{name}/unabandon",
     *      name="unabandon_package",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"}
     * )
     */
    public function unabandonAction(#[Vars] Package $package)
    {
        if (!$package->getMaintainers()->contains($this->getUser()) && !$this->isGranted('ROLE_EDIT_PACKAGES')) {
            throw new AccessDeniedException;
        }

        $package->setAbandoned(false);
        $package->setReplacementPackage(null);
        $package->setIndexedAt(null);
        $package->setCrawledAt(new \DateTime());
        $package->setUpdatedAt(new \DateTime());

        $em = $this->registry->getManager();
        $em->flush();

        return $this->redirect($this->generateUrl('view_package', ['name' => $package->getName()]));
    }

    /**
     * @Route(
     *      "/packages/{name}/stats.{_format}",
     *      name="view_package_stats",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "_format"="(json)"},
     *      defaults={"_format"="html"}
     * )
     */
    public function statsAction(Request $req, #[Vars('name')] Package $package)
    {
        if (!$this->isGranted('ROLE_MAINTAINER')) {
            throw new AccessDeniedException;
        }

        $versions = $package->getVersions()->toArray();
        usort($versions, Package::class.'::sortVersions');
        $date = $this->guessStatsStartDate($package);
        $data = [
            'downloads' => $this->downloadManager->getDownloads($package),
            'versions' => $versions,
            'average' => $this->guessStatsAverage($date),
            'date' => $date->format('Y-m-d'),
        ];

        if ($req->getRequestFormat() === 'json') {
            $data['versions'] = array_map(function ($version) {
                /** @var Version $version */
                return $version->getVersion();
            }, $data['versions']);

            return new JsonResponse($data);
        }

        $data['package'] = $package;

        $expandedVersion = reset($versions);
        foreach ($versions as $v) {
            /** @var Version $v */
            if (!$v->isDevelopment()) {
                $expandedVersion = $v;
                break;
            }
        }
        $data['expandedId'] = $expandedVersion ? $expandedVersion->getId() : false;

        return $this->render('package/stats.html.twig', $data);
    }

    /**
     * @Route(
     *      "/packages/{name}/dependents",
     *      name="view_package_dependents",
     *      requirements={"name"="([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?|ext-[A-Za-z0-9_.-]+?)"}
     * )
     */
    public function dependentsAction(Request $req, $name)
    {
        if (!$this->isGranted('ROLE_MAINTAINER')) {
            throw new AccessDeniedException;
        }

        $page = $req->query->get('page', 1);

        /** @var PackageRepository $repo */
        $repo = $this->registry->getRepository(Package::class);
        $depCount = $repo->getDependentCount($name);
        $packages = $repo->getDependents($name, ($page - 1) * 15, 15);

        $paginator = new Pagerfanta(new FixedAdapter($depCount, $packages));
        $paginator->setMaxPerPage(15);
        $paginator->setCurrentPage($page, false, true);

        $data['packages'] = $paginator;
        $data['count'] = $depCount;

        $data['meta'] = $this->getPackagesMetadata($data['packages']);
        $data['name'] = $name;

        return $this->render('package/dependents.html.twig', $data);
    }

    /**
     * @Route(
     *      "/packages/{name}/suggesters",
     *      name="view_package_suggesters",
     *      requirements={"name"="([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?|ext-[A-Za-z0-9_.-]+?)"}
     * )
     */
    public function suggestersAction(Request $req, $name)
    {
        if (!$this->isGranted('ROLE_MAINTAINER')) {
            throw new AccessDeniedException;
        }

        $page = $req->query->get('page', 1);

        /** @var PackageRepository $repo */
        $repo = $this->registry->getRepository(Package::class);
        $suggestCount = $repo->getSuggestCount($name);
        $packages = $repo->getSuggests($name, ($page - 1) * 15, 15);

        $paginator = new Pagerfanta(new FixedAdapter($suggestCount, $packages));
        $paginator->setMaxPerPage(15);
        $paginator->setCurrentPage($page);

        $data['packages'] = $paginator;
        $data['count'] = $suggestCount;

        $data['meta'] = $this->getPackagesMetadata($data['packages']);
        $data['name'] = $name;

        return $this->render('package/suggesters.html.twig', $data);
    }

    /**
     * @Route(
     *      "/packages/{name}/stats/all.json",
     *      name="package_stats",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"}
     * )
     */
    public function overallStatsAction(Request $req, \Redis $redis, #[Vars('name')] Package $package, Version $version = null)
    {
        if (!$this->isGranted('ROLE_MAINTAINER')) {
            throw new AccessDeniedException;
        }

        if ($from = $req->query->get('from')) {
            $from = new \DateTimeImmutable($from);
        } else {
            $from = $this->guessStatsStartDate($version ?: $package);
        }
        if ($to = $req->query->get('to')) {
            $to = new \DateTimeImmutable($to);
        } else {
            $to = new \DateTimeImmutable('-2days 00:00:00');
        }
        $average = $req->query->get('average', $this->guessStatsAverage($from, $to));

        $datePoints = $this->createDatePoints($from, $to, $average, $package, $version);

        if ($average === 'daily') {
            $datePoints = array_map(function ($vals) {
                return $vals[0];
            }, $datePoints);

            if (count($datePoints)) {
                $datePoints = [
                    'labels' => array_keys($datePoints),
                    'values' => array_map(function ($val) {
                        return (int) $val;
                    }, $redis->mget(array_values($datePoints))),
                ];
            } else {
                $datePoints = [
                    'labels' => [],
                    'values' => [],
                ];
            }
        } else {
            $datePoints = [
                'labels' => array_keys($datePoints),
                'values' => array_values(array_map(function ($vals) use ($redis) {
                    return array_sum($redis->mget(array_values($vals)));
                }, $datePoints))
            ];
        }

        $datePoints['average'] = $average;

        if ($average !== 'daily') {
            $dividers = [
                'monthly' => 30.41,
                'weekly' => 7,
            ];
            $divider = $dividers[$average];
            $datePoints['values'] = array_map(function ($val) use ($divider) {
                return ceil($val / $divider);
            }, $datePoints['values']);
        }

        if (empty($datePoints['labels']) && empty($datePoints['values'])) {
            $datePoints['labels'][] = date('Y-m-d');
            $datePoints['values'][] = 0;
        }

        $response = new JsonResponse($datePoints);
        $response->setSharedMaxAge(1800);

        return $response;
    }

    /**
     * @Route(
     *      "/packages/{name}/stats/{version}.json",
     *      name="version_stats",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "version"=".+?"}
     * )
     */
    public function versionStatsAction(\Redis $redis, Request $req, #[Vars('name')] Package $package, $version)
    {
        if (!$this->isGranted('ROLE_MAINTAINER')) {
            throw new AccessDeniedException;
        }

        $normalizer = new VersionParser;
        $normVersion = $normalizer->normalize($version);

        $version = $this->registry->getRepository(Version::class)->findOneBy([
            'package' => $package,
            'normalizedVersion' => $normVersion
        ]);

        if (!$version) {
            throw new NotFoundHttpException();
        }

        return $this->overallStatsAction($req, $redis, $package, $version);
    }

    private function createAddMaintainerForm(Package $package)
    {
        if (!$user = $this->getUser()) {
            return null;
        }

        if ($this->isGranted('ROLE_EDIT_PACKAGES') || $package->getMaintainers()->contains($user)) {
            $maintainerRequest = new MaintainerRequest();
            return $this->createForm(AddMaintainerRequestType::class, $maintainerRequest);
        }

        return null;
    }

    private function createRemoveMaintainerForm(Package $package)
    {
        if (!($user = $this->getUser()) || 1 == $package->getMaintainers()->count()) {
            return null;
        }

        if ($this->isGranted('ROLE_EDIT_PACKAGES') || $package->getMaintainers()->contains($user)) {
            $maintainerRequest = new MaintainerRequest();
            return $this->createForm(RemoveMaintainerRequestType::class, $maintainerRequest, [
                'package' => $package,
            ]);
        }

        return null;
    }

    private function createDeletePackageForm(Package $package)
    {
        if (!$user = $this->getUser()) {
            return null;
        }

        // super admins bypass additional checks
        if (!$this->isGranted('ROLE_DELETE_PACKAGES')) {
            // non maintainers can not delete
            if (!$package->getMaintainers()->contains($user)) {
                return null;
            }

            $downloads = $this->downloadManager->getTotalDownloads($package);

            // more than 100 downloads = established package, do not allow deletion by maintainers
            if ($downloads > 100) {
                return null;
            }
        }

        return $this->createFormBuilder([])->getForm();
    }

    private function createDatePoints(\DateTimeImmutable $from, \DateTimeImmutable $to, $average, Package $package, Version $version = null)
    {
        $interval = $this->getStatsInterval($average);

        $dateKey = $average === 'monthly' ? 'Ym' : 'Ymd';
        $dateFormat = $average === 'monthly' ? 'Y-m' : 'Y-m-d';
        $dateJump = $average === 'monthly' ? '+1month' : '+1day';
        if ($average === 'monthly') {
            $to = new \DateTimeImmutable('last day of '.$to->format('Y-m'));
        }

        $nextDataPointLabel = $from->format($dateFormat);
        $nextDataPoint = $from->modify($interval);

        $datePoints = [];
        while ($from <= $to) {
            $datePoints[$nextDataPointLabel][] = 'dl:'.$package->getId().($version ? '-' . $version->getId() : '').':'.$from->format($dateKey);

            $from = $from->modify($dateJump);
            if ($from >= $nextDataPoint) {
                $nextDataPointLabel = $from->format($dateFormat);
                $nextDataPoint = $from->modify($interval);
            }
        }

        return $datePoints;
    }

    private function guessStatsStartDate($packageOrVersion)
    {
        if ($packageOrVersion instanceof Package) {
            $date = \DateTimeImmutable::createFromMutable($packageOrVersion->getCreatedAt());
        } elseif ($packageOrVersion instanceof Version) {
            $date = \DateTimeImmutable::createFromMutable($packageOrVersion->getReleasedAt());
        } else {
            throw new \LogicException('Version or Package expected');
        }

        $statsRecordDate = new \DateTimeImmutable('2012-04-13 00:00:00');
        if ($date < $statsRecordDate) {
            $date = $statsRecordDate;
        }

        return $date->setTime(0, 0, 0);
    }

    private function guessStatsAverage(\DateTimeImmutable $from, \DateTimeImmutable $to = null)
    {
        if ($to === null) {
            $to = new \DateTimeImmutable('-2 days');
        }
        if ($from < $to->modify('-48months')) {
            $average = 'monthly';
        } elseif ($from < $to->modify('-7months')) {
            $average = 'weekly';
        } else {
            $average = 'daily';
        }

        return $average;
    }

    private function getStatsInterval($average)
    {
        $intervals = [
            'monthly' => '+1month',
            'weekly' => '+7days',
            'daily' => '+1day',
        ];

        if (!isset($intervals[$average])) {
            throw new BadRequestHttpException();
        }

        return $intervals[$average];
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedServices(): array
    {
        return array_merge(
            parent::getSubscribedServices(),
            [
                Scheduler::class,
                PackageManager::class
            ]
        );
    }
}
