<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Webhook;

use Doctrine\Common\Util\ClassUtils;
use Twig\Extension\ExtensionInterface;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Sandbox\SecurityNotAllowedMethodError;
use Twig\Sandbox\SecurityNotAllowedPropertyError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityPolicyInterface;

class WebhookSecurityPolicy implements SecurityPolicyInterface
{
    private $strictSecurityPolicy;
    private $forbiddenFilters;
    private $forbiddenMethods;
    private $forbiddenProperties;
    private $forbiddenFunctions;
    private $forbiddenClasses;
    private $forbiddenTags;

    /** @var ExtensionInterface[] */
    private $allowedExtensions;
    private $allowedFunctions;
    private $allowedTags;
    private $allowedFilters;

    public function __construct(SecurityPolicyInterface $strictSecurityPolicy, array $forbiddenTags = null, array $forbiddenFilters = null, array $forbiddenFunctions = null, array $forbiddenMethods = null, array $forbiddenProperties = null, array $forbiddenClasses = [])
    {
        $this->strictSecurityPolicy = $strictSecurityPolicy;
        $this->forbiddenTags = $forbiddenTags;
        $this->forbiddenFilters = $forbiddenFilters;
        $this->setForbiddenMethods($forbiddenMethods);
        $this->forbiddenClasses = $forbiddenClasses;
        $this->forbiddenFunctions = $forbiddenFunctions;
        $this->forbiddenProperties = $forbiddenProperties;
    }

    /**
     * {@inheritdoc}
     */
    public function checkSecurity($tags, $filters, $functions)
    {
        if (null === $this->allowedTags) {
            $this->initExtensions();
        }

        if (is_array($this->forbiddenTags)) {
            if ($items = array_intersect($tags, $this->forbiddenTags)) {
                throw new SecurityNotAllowedTagError(sprintf('Tags "%s" are not allowed.', implode(',', $items)), reset($items));
            }
            $tags = [];
        }

        if (is_array($this->forbiddenFilters)) {
            if ($items = array_intersect($filters, $this->forbiddenFilters)) {
                throw new SecurityNotAllowedFilterError(sprintf('Filters "%s" are not allowed.', implode(',', $items)), reset($items));
            }
            $filters = [];
        }

        if (is_array($this->forbiddenFunctions)) {
            if ($items = array_intersect($functions, $this->forbiddenFunctions)) {
                throw new SecurityNotAllowedFunctionError(sprintf('Functions "%s" are not allowed.', implode(',', $items)), reset($items));
            }
            $functions = [];
        }

        foreach ($tags as $i => $tag) {
            if (in_array($tag, $this->allowedTags)) {
                unset($tags[$i]);
            }
        }
        foreach ($functions as $i => $function) {
            if (in_array($function, $this->allowedFunctions)) {
                unset($functions[$i]);
            }
        }
        foreach ($filters as $i => $filter) {
            if (in_array($filter, $this->allowedFilters)) {
                unset($filters[$i]);
            }
        }

        $this->strictSecurityPolicy->checkSecurity($tags, $filters, $functions);
    }

    public function setForbiddenMethods(array $methods = null)
    {
        if (null === $this->forbiddenMethods) {
            return;
        }

        $this->forbiddenMethods = [];
        foreach ($methods as $class => $m) {
            $this->forbiddenMethods[$class] = array_map('strtolower', \is_array($m) ? $m : [$m]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function checkMethodAllowed($obj, $method)
    {
        $class = ClassUtils::getClass($obj);
        if (false === $this->checkClassAllowed($obj)) {
            throw new SecurityNotAllowedMethodError(sprintf('Class "%s" object is not allowed.', $class), $class, $method);
        }

        try {
            $this->strictSecurityPolicy->checkMethodAllowed($obj, $method);
            return;
        } catch (SecurityError $exception) {
            if (null === $this->forbiddenMethods) {
                throw $exception;
            }
        }

        $forbid = false;
        $method = strtolower($method);
        foreach ($this->forbiddenMethods as $class => $methods) {
            if ($obj instanceof $class) {
                $forbid = \in_array($method, $methods);

                break;
            }
        }

        if ($forbid) {
            $class = \get_class($obj);
            throw new SecurityNotAllowedMethodError(sprintf('Calling "%s" method on a "%s" object is not allowed.', $method, $class), $class, $method);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function checkPropertyAllowed($obj, $property)
    {
        $class = ClassUtils::getClass($obj);
        if (false === $this->checkClassAllowed($obj)) {
            throw new SecurityNotAllowedPropertyError(sprintf('Class "%s" object is not allowed.', $class), $class, $property);
        }

        try {
            $this->strictSecurityPolicy->checkPropertyAllowed($obj, $property);
            return;
        } catch (SecurityError $exception) {
            if (null === $this->forbiddenProperties) {
                throw $exception;
            }
        }

        $forbid = false;
        foreach ($this->forbiddenProperties as $class => $properties) {
            if ($obj instanceof $class) {
                $forbid = \in_array($property, \is_array($properties) ? $properties : [$properties]);

                break;
            }
        }

        if ($forbid) {
            throw new SecurityNotAllowedPropertyError(sprintf('Calling "%s" property on a "%s" object is not allowed.', $property, $class), $class, $property);
        }
    }

    /**
     * @param ExtensionInterface[] $extensions
     */
    public function setAllowedExtension(iterable $extensions): void
    {
        $this->allowedTags = $this->allowedFunctions = $this->allowedFilters = null;
        $this->allowedExtensions = $extensions;
    }

    private function checkClassAllowed($obj): bool
    {
        foreach ($this->forbiddenClasses as $forbiddenClass) {
            if ($obj instanceof $forbiddenClass) {
                return false;
            }
        }

        return true;
    }

    private function initExtensions(): void
    {
        $this->allowedTags = $this->allowedFunctions = $this->allowedFilters = [];

        foreach ($this->allowedExtensions as $extension) {
            foreach ($extension->getFilters() as $filter) {
                $this->allowedFilters[] = $filter->getName();
            }
            foreach ($extension->getTokenParsers() as $parser) {
                $this->allowedTags[] = $parser->getTag();
            }
            foreach ($extension->getFunctions() as $function) {
                $this->allowedFunctions[] = $function->getName();
            }
        }
    }
}
