<?php

namespace Packagist\WebBundle\Webhook\Twig;

use Doctrine\Persistence\ManagerRegistry;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\Version;
use Packagist\WebBundle\Entity\Webhook;
use Packagist\WebBundle\Util\ChangelogUtils;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * This extension only apply for webhook sandbox env.
 */
class WebhookExtension extends AbstractExtension implements ContextAwareInterface
{
    private $registry;
    private $changelogUtils;

    /** @var WebhookContext */
    private $context;

    public function __construct(
        ManagerRegistry $registry,
        ChangelogUtils $changelogUtils
    ) {
        $this->registry = $registry;
        $this->changelogUtils = $changelogUtils;
    }

    /**
     * {@inheritdoc}
     */
    public function getFunctions()
    {
        $functions = [];
        $reflect = new \ReflectionClass(__CLASS__);
        foreach ($reflect->getMethods() as $method) {
            if ($method->isPublic() && strpos($method->getName(), 'hook_function_') === 0) {
                $functions[] = new TwigFunction(substr($method->getName(), 14), [$this, $method->getName()]);
            }
        }

        return $functions;
    }

    /**
     * Get commit messages between two tags
     *
     * {@inheritDoc}
     */
    public function hook_function_get_changelog($package, $fromVersion = null, $toVersion = null, $maxCount = 100)
    {
        $repo = $this->registry->getRepository(Package::class);
        if (is_int($package)) {
            $package = $repo->find($package);
        } elseif (is_string($package)) {
            $package = $repo->findOneBy(['name' => $package]);
        }

        if (!is_string($toVersion) || !$package instanceof Package) {
            return [];
        }
        if (!is_int($maxCount)) {
            $maxCount = 100;
        }

        if (!is_string($fromVersion)) {
            $fromVersion = $this->registry->getRepository(Version::class)
                ->getPreviousRelease($package->getName(), $toVersion);
        }
        if ($toVersion && $fromVersion) {
            return $this->changelogUtils->getChangelog($package, $fromVersion, $toVersion, $maxCount);
        }

        return [];
    }

    public function hook_function_preg_match_all($regex, $content, $matchOffset = null)
    {
        try {
            @preg_match_all($regex, $content, $matches);
        } catch (\Throwable $e) {
            return [];
        }

        return is_int($matchOffset) ? $matches[$matchOffset] ?? [] : $matches;
    }

    /**
     * Interrupt request
     *
     * {@inheritDoc}
     */
    public function hook_function_interrupt($reason = null)
    {
        throw new InterruptException('This request was interrupt by user from twig code. ' . $reason);
    }

    /**
     * Trigger other webhook by ID
     *
     * {@inheritdoc}
     */
    public function hook_function_trigger_webhook($hookId, array $context = [])
    {
        if (!$this->context instanceof WebhookContext) {
            return;
        }

        $hook = $hookId instanceof Webhook ? $hookId :
            $this->registry->getRepository(Webhook::class)->find((int) $hookId);
        if (null !== $hook) {
            $this->context[WebhookContext::CHILD_WEBHOOK][] = [$hook, $context];
        }
    }

    /**
     * @inheritDoc
     */
    public function setContext(WebhookContext $context = null): void
    {
        $this->context = $context;
    }
}
