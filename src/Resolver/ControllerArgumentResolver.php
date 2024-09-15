<?php

declare(strict_types=1);

namespace Packeton\Resolver;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Attribute\Vars;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ControllerArgumentResolver implements ValueResolverInterface
{
    public function __construct(
        private readonly ManagerRegistry $registry
    ){
    }

    /**
     * {@inheritdoc}
     */
    public function supports(Request $request, ArgumentMetadata $argument): bool
    {
        return \count($argument->getAttributes(Vars::class, ArgumentMetadata::IS_INSTANCEOF)) > 0 &&
            \count($this->getRequestParams($request)) > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (false === $this->supports($request, $argument)) {
            return [];
        }

        /** @var Vars $attr */
        $attr = $argument->getAttributes(Vars::class, ArgumentMetadata::IS_INSTANCEOF)[0];

        $mapping = [];
        if (\is_string($attr->map)) {
            $mapping[$attr->map] = $request->attributes->has($argument->getName()) ?
                $request->attributes->get($argument->getName()) :
                $request->attributes->get($attr->map);
        } elseif (\is_array($attr->map)) {
            foreach ($attr->map as $name => $field) {
                $mapping[$field] = $request->attributes->get($name);
            }
        } else {
            $metadata = $this->registry->getManagerForClass($argument->getType())->getClassMetadata($argument->getType());
            $identifier = $metadata->getIdentifier()[0];

            if ($request->attributes->has($identifier)) {
                $mapping[$identifier] = $request->attributes->get($identifier);
            } elseif (\count($params = $this->getRequestParams($request)) === 1) {
                $identifier = \array_key_first($params);
                if ($metadata->hasField($identifier)) {
                    $mapping[$identifier] = $params[$identifier];
                }
            }
        }

        if (empty($mapping)) {
            if ($argument->hasDefaultValue()) {
                return [$argument->getDefaultValue()];
            }
            throw new \UnexpectedValueException('Cannot resolve $'.$argument->getName() . ' argument');
        }

        foreach ($mapping as $varName => $value) {
            if (empty($value)) {
                if ($argument->hasDefaultValue()) {
                    return [$argument->getDefaultValue()];
                }
                throw new \UnexpectedValueException('Missing "'.$varName.'" in request attributes, cannot resolve $'.$argument->getName());
            }
        }

        if (!$object = $this->registry->getRepository($argument->getType())->findOneBy($mapping)) {
            throw new NotFoundHttpException('Object not found');
        }

        return [$object];
    }

    private function getRequestParams(Request $request): array
    {
        $params = $request->attributes->get('_route_params', []);
        unset($params['_format']);

        return  $params;
    }
}
