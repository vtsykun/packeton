<?php

declare(strict_types=1);

namespace Packeton\Controller\Api;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Attribute\Vars;
use Packeton\Controller\ControllerTrait;
use Packeton\Entity\Job;
use Packeton\Entity\OAuthIntegration;
use Packeton\Entity\Package;
use Packeton\Entity\User;
use Packeton\Entity\Webhook;
use Packeton\Integrations\IntegrationRegistry;
use Packeton\Integrations\Model\AppUtils;
use Packeton\Model\AutoHookUser;
use Packeton\Model\DownloadManager;
use Packeton\Model\PackageManager;
use Packeton\Security\Provider\AuditSessionProvider;
use Packeton\Service\JobPersister;
use Packeton\Service\Scheduler;
use Packeton\Util\PacketonUtils;
use Packeton\Webhook\HookBus;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route(defaults: ['_format' => 'json'])]
class ApiController extends AbstractController
{
    use ControllerTrait;

    public function __construct(
        protected ManagerRegistry $registry,
        protected DownloadManager $downloadManager,
        protected LoggerInterface $logger,
        protected ValidatorInterface $validator,
        protected IntegrationRegistry $integrations,
        protected AuditSessionProvider $auditSessionProvider,
    ) {
    }

    #[Route('/api/create-package', name: 'generic_create', methods: ['POST'])]
    public function createPackageAction(Request $request): Response
    {
        $payload = $this->getJsonPayload($request);

        if (!$payload || empty($url = $payload['repository']['url'] ?? null)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Missing payload repository->url parameter'], 406);
        }

        $package = new Package;
        if ($this->getUser() instanceof User) {
            $package->addMaintainer($this->getUser());
        }

        $package->setRepository($url);
        $this->container->get(PackageManager::class)->updatePackageUrl($package);
        $errors = $this->validator->validate($package, null, ['Create']);
        if (\count($errors) > 0) {
            $errorArray = [];
            foreach ($errors as $error) {
                $errorArray[$error->getPropertyPath()] =  $error->getMessage();
            }
            return new JsonResponse(['status' => 'error', 'message' => $errorArray], 406);
        }
        try {
            $em = $this->registry->getManager();
            $em->persist($package);
            $em->flush();

            $this->container->get(PackageManager::class)->insetPackage($package);
        } catch (\Exception $e) {
            $this->logger->critical($e->getMessage(), ['exception', $e]);
            return new JsonResponse(['status' => 'error', 'message' => 'Error saving package'], 500);
        }

        $job = $this->container->get(Scheduler::class)->scheduleUpdate($package);

        return new JsonResponse(['status' => 'success', 'job' => $job->getId()], 202);
    }

    #[Route('/api/hooks/{alias}/{id}', name: 'api_integration_postreceive')]
    public function integrationHook(Request $request, string $alias, #[Vars] OAuthIntegration $oauth): Response
    {
        if ($alias !== $oauth->getAlias()) {
            return new JsonResponse(['error' => "App $alias is not found"], 409);
        }

        $response = $this->receiveIntegrationHook($request, $oauth);
        if (null !== $response) {
            return new JsonResponse($response, $response['code'] ?? 200);
        }

        return $this->updatePackageAction($request, fallback: true);
    }

    #[Route('/api/github', name: 'github_postreceive')]
    #[Route('/api/bitbucket', name: 'bitbucket_postreceive')]
    #[Route('/api/update-package', name: 'generic_postreceive')]
    #[Route('/api/update-package/{name}', name: 'generic_named_postreceive', requirements: ['name' => '%package_name_regex%'])]
    public function updatePackageAction(Request $request, #[Vars] Package $package = null, bool $fallback = false): Response
    {
        // parse the payload
        $payload = $this->getJsonPayload($request);

        if (!$payload && !$request->get('composer_package_name') && null === $package) {
            return new JsonResponse(['status' => 'error', 'message' => 'Missing payload parameter'], 406);
        }

        // May helpfully for GitLab Packagist Integrations. Replacement for group webhooks that enabled only for PAID EE version
        // See docs how to use GitLab Integrations
        if (false === $fallback && $this->getUser() instanceof AutoHookUser && null !== ($response = $this->receiveIntegrationHook($request))) {
            return new JsonResponse($response, $response['code'] ?? 200);
        }

        $packages = [$package];
        // Get from query parameter.
        $repo = $this->registry->getRepository(Package::class);
        if ($packageNames = $request->get('composer_package_name')) {
            $packageNames = \explode(',', $packageNames);
            foreach ($packageNames as $packageName) {
                $packages = array_merge($packages, $repo->findBy(['name' => $packageName]));
            }
        }

        $packages = array_values(array_filter($packages));
        if (empty($packages) && isset($payload['composer']['package_name'])) { // custom webhook
            $packages = [];
            $packageNames = (array) $payload['composer']['package_name'];
            foreach ($packageNames as $packageName) {
                $packages = \array_merge($packages, $repo->findBy(['name' => $packageName]));
            }
        }
        if (empty($packages)) {
            if (!$packages = PacketonUtils::findPackagesByPayload($payload, $repo, true)) {
                return new JsonResponse(['status' => 'error', 'message' => 'Missing or invalid payload'], 406);
            }
        }

        return $this->schedulePostJobs($packages);
    }

    #[Route('/api/packages/{name}', name: 'api_edit_package', requirements: ['name' => '%package_name_regex%'], methods: ['PUT'])]
    public function editPackageAction(Request $request, #[Vars] Package $package): Response
    {
        $user = $this->getUser();
        if (!$package->getMaintainers()->contains($user) && !$this->isGranted('ROLE_EDIT_PACKAGES')) {
            throw new AccessDeniedException();
        }
        if (!$package->isUpdatable()) {
            return new JsonResponse(['status' => 'error', 'message' => 'Package is readonly'], 400);
        }

        $payload = $this->getJsonPayload($request);

        $package->setRepository($payload['repository']);
        $this->container->get(PackageManager::class)->updatePackageUrl($package);
        $errors = $this->validator->validate($package, null, ["Update"]);
        if (\count($errors) > 0) {
            $errorArray = [];
            foreach ($errors as $error) {
                $errorArray[$error->getPropertyPath()] =  $error->getMessage();
            }
            return new JsonResponse(['status' => 'error', 'message' => $errorArray], 406);
        }

        $package->setCrawledAt(null);

        $em = $this->registry->getManager();
        $em->persist($package);
        $em->flush();

        return new JsonResponse(['status' => 'success'], 200);
    }


    #[Route('/downloads/{name}', name: 'track_download', requirements: ['name' => '%package_name_regex%'], methods: ['POST'])]
    public function trackDownloadAction(Request $request, $name): Response
    {
        $result = $this->getPackageAndVersionId($name, $request->request->get('version_normalized'));

        if (!$result) {
            return new JsonResponse(['status' => 'error', 'message' => 'Package not found'], 200);
        }

        $this->downloadManager->addDownloads(['id' => $result['id'], 'vid' => $result['vid'], 'ip' => $request->getClientIp()]);

        return new JsonResponse(['status' => 'success'], 201);
    }


    #[Route('/jobs/{id}', name: 'get_job', requirements: ['id' => '[a-f0-9]+'], methods: ['GET'])]
    public function getJobAction(string $id): Response
    {
        return new JsonResponse($this->container->get(Scheduler::class)->getJobStatus($id), 200);
    }

    /**
     * Expects a json like:
     *
     * {
     *     "downloads": [
     *         {"name": "foo/bar", "version": "1.0.0.0"},
     *         // ...
     *     ]
     * }
     *
     * The version must be the normalized one
     * @inheritDoc
     */
    #[Route('/downloads/', name: 'track_download_batch', methods: ['POST'])]
    public function trackDownloadsAction(Request $request): Response
    {
        $contents = \json_decode($request->getContent(), true);
        if (empty($contents['downloads']) || !is_array($contents['downloads'])) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid request format, must be a json object containing a downloads key filled with an array of name/version objects'], 200);
        }

        $audit = $failed = [];
        $ip = $request->getClientIp();

        $jobs = [];
        foreach ($contents['downloads'] as $package) {
            $result = $this->getPackageAndVersionId($package['name'], $package['version']);

            if (!$result) {
                $failed[] = $package;
                continue;
            }

            $audit[] = "{$package['name']}: {$package['version']}";
            $jobs[] = ['id' => $result['id'], 'vid' => $result['vid'], 'ip' => $ip];
        }

        $this->downloadManager->addDownloads($jobs);

        if (null !== ($user = $this->getUser())) {
            $this->auditSessionProvider->logDownload($request, $user, $audit);
        }

        if ($failed) {
            return new JsonResponse(['status' => 'partial', 'message' => 'Packages '. json_encode($failed).' not found'], 200);
        }

        return new JsonResponse(['status' => 'success'], 201);
    }


    #[Route('/api/webhook-invoke/{name}', name: 'generic_webhook_invoke', defaults: ['name' => 'default'], methods: ['POST'])]
    public function notifyWebhookAction($name, Request $request): Response
    {
        $payload = \array_merge($request->request->all(), $request->query->all());
        unset($payload['token']);

        $payload = $payload + ($this->getJsonPayload($request) ?: []);

        $context = [
            'event' => Webhook::HOOK_HTTP_REQUEST,
            'name' => $name,
            'ip_address' => $request->getClientIp(),
            'request' => $payload
        ];

        $jobs = [];
        $bus = $this->container->get(HookBus::class);
        $webhooks = $this->registry->getRepository(Webhook::class)
            ->findActive($name,  [Webhook::HOOK_HTTP_REQUEST]);

        foreach ($webhooks as $webhook) {
            $jobs[] = $bus->dispatch($context, $webhook)->getId();
        }

        return new JsonResponse(['status' => 'success', 'jobs' => $jobs], \count($jobs) === 0 ? 200 : 202);
    }

    /**
     * @param string $name
     * @param string $version
     * @return array
     */
    protected function getPackageAndVersionId($name, $version)
    {
        return $this->getEM()->getConnection()->fetchAssociative(
            'SELECT p.id, v.id as vid
            FROM package p
            LEFT JOIN package_version v ON p.id = v.package_id
            WHERE p.name = ?
            AND v.normalizedVersion = ?
            LIMIT 1',
            [$name, $version]
        );
    }

    protected function getJsonPayload(Request $request): ?array
    {
        $payload = $request->request->get('payload') ? \json_decode($request->request->get('payload'), true) : null;
        if (!$payload and $content = $request->getContent()) {
            $payload = @\json_decode($content, true);
        }

        return \is_array($payload) ? $payload : null;
    }

    protected function receiveIntegrationHook(Request $request, OAuthIntegration $oauth = null): ?array
    {
        $user = $this->getUser();
        if (null === $oauth && $user instanceof AutoHookUser) {
            $oauth = $this->registry->getRepository(OAuthIntegration::class)->find((int) $user->getHookIdentifier());
        }

        if (null === $oauth) {
            return null;
        }

        $job = AppUtils::createLogJob($request, $oauth);
        $response = $app = null;
        try {
            $app = $this->integrations->findApp($oauth->getAlias());
            $response = $app->receiveHooks($oauth, $request, $this->getJsonPayload($request));
            $job->setStatus(Job::STATUS_COMPLETED);
        } catch (\Throwable $e) {
            $error = AppUtils::castError($e, $app, true);
            $this->logger->error($error, ['e' => $e]);

            $job->setStatus(Job::STATUS_ERRORED);
            $job->addResult('error', $error);
        }

        $job->addResult('response', $response);
        try {
            $this->container->get(JobPersister::class)->persist($job, false);
        } catch (\Throwable $e) {}

        return $response;
    }

    /**
     * @param Package[] $packages
     * @return Response
     */
    protected function schedulePostJobs(array|null $packages)
    {
        if (!$packages) {
            return new Response(\json_encode(['status' => 'error', 'message' => 'Could not find a package that matches this request (does user maintain the package?)']), 404);
        }

        $jobs = [];

        foreach ($packages as $package) {
            if (false === $package->isUpdatable()) {
                continue;
            }

            $package->setAutoUpdated(true);
            $this->getEM()->flush($package);

            $job = $this->container->get(Scheduler::class)->scheduleUpdate($package);
            $jobs[] = $job->getId();
        }

        return new JsonResponse(['status' => 'success', 'jobs' => $jobs], 202);
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedServices(): array
    {
        return \array_merge(
            parent::getSubscribedServices(),
            [
                PackageManager::class,
                Scheduler::class,
                HookBus::class,
                JobPersister::class,
            ]
        );
    }
}
