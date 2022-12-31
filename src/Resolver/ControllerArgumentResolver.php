<?php

declare(strict_types=1);

namespace Packeton\Resolver;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Attribute\Vars;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ArgumentValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ControllerArgumentResolver implements ArgumentValueResolverInterface
{
    public function __construct(
        private readonly ManagerRegistry $registry
    ){}

    /**
     * {@inheritdoc}
     */
    public function supports(Request $request, ArgumentMetadata $argument)
    {
        return count($argument->getAttributes(Vars::class, ArgumentMetadata::IS_INSTANCEOF)) > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function resolve(Request $request, ArgumentMetadata $argument)
    {
        /** @var Vars $attr */
        $attr = $argument->getAttributes(Vars::class, ArgumentMetadata::IS_INSTANCEOF)[0];

        $mapping = [];
        if (is_string($attr->map)) {
            $mapping[$attr->map] = $request->attributes->has($argument->getName()) ?
                $request->attributes->get($argument->getName()) :
                $request->attributes->get($attr->map);
        } elseif (is_array($attr->map)) {
            foreach ($attr->map as $name => $field) {
                $mapping[$field] = $request->attributes->get($name);
            }
        } else {
            $metadata = $this->registry->getManagerForClass($argument->getType())->getClassMetadata($argument->getType());
            $identifier = $metadata->getIdentifier()[0];

            if ($request->attributes->has($identifier)) {
                $mapping[$identifier] = $request->attributes->get($identifier);
            } elseif (count($params = $request->attributes->get('_route_params', [])) === 1) {
                $identifier = array_key_first($params);
                if ($metadata->hasField($identifier)) {
                    $mapping[$identifier] = $params[$identifier];
                }
            }
        }

        if (empty($mapping)) {
            throw new \UnexpectedValueException('Cannot resolve $'.$argument->getName() . ' argument');
        }

        foreach ($mapping as $varName => $value) {
            if (empty($value)) {
                throw new \UnexpectedValueException('Missing "'.$varName.'" in request attributes, cannot resolve $'.$argument->getName());
            }
        }

        if (!$object = $this->registry->getRepository($argument->getType())->findOneBy($mapping)) {
            throw new NotFoundHttpException('Object not found');
        }

        yield $object;
    }
}
