<?php

declare(strict_types=1);

namespace Packeton\Webhook\Serializer;

use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class JobDataSerializer implements NormalizerInterface, DenormalizerInterface
{
    private $registry;

    public function __construct(ManagerRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * {@inheritdoc}
     */
    public function denormalize($data, $type, $format = null, array $context = [])
    {
        if (!is_array($data)) {
            return $data;
        }

        if (isset($data['@id'], $data['@entity_class'])) {
            return $this->registry->getRepository($data['@entity_class'])
                ->find($data['@id']);
        }

        $result = [];
        foreach ($data as $key => $value) {
            $result[$key] = $this->denormalize($value, $type, $format, $context);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsDenormalization($data, $type, $format = null)
    {
        return $format === 'packagist_job' && is_array($data);
    }

    /**
     * {@inheritdoc}
     */
    public function normalize($object, $format = null, array $context = [])
    {
        $className = ClassUtils::getClass($object);
        /** @var EntityManagerInterface $em */
        $em = $this->registry->getManagerForClass($className);

        return [
            '@id' => $em->getClassMetadata($className)->getSingleIdReflectionProperty()->getValue($object),
            '@entity_class' => $className
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function supportsNormalization($data, $format = null)
    {
        return $format === 'packagist_job'
            && is_object($data)
            && $this->registry->getManagerForClass(ClassUtils::getClass($data));
    }
}
