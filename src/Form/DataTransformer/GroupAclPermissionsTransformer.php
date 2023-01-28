<?php

namespace Packeton\Form\DataTransformer;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\GroupAclPermission;
use Packeton\Entity\Package;
use Packeton\Form\Model\PackagePermission;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

class GroupAclPermissionsTransformer implements DataTransformerInterface
{
    protected $registry;

    /**
     * @param ManagerRegistry $registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * {@inheritdoc}
     */
    public function transform($value): mixed
    {
        if (null === $value) {
            return '';
        }

        if ($value instanceof Collection) {
            $value = $value->map(
                function (GroupAclPermission $permission) {
                    $model = new PackagePermission();
                    $model->setVersion($permission->getVersion());
                    $model->setSelected((bool) $permission->getGroup());
                    try {
                        $model->setName($permission->getPackage()->getName());
                    } catch (EntityNotFoundException) { // If SQLite FK error
                        $model->setName('DELETED');
                    }

                    return $model;
                }
            );

            $value = $value->toArray();
            usort($value, fn($a, $b) => $a->getSelected() ^ $b->getSelected() === 0 ?  $a->getName() <=> $b->getName() : -1 * ($a->getSelected() <=> $b->getSelected()));
        }

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function reverseTransform($value): mixed
    {
        if (empty($value)) {
            return $value;
        }

        if (\is_array($value)) {
            $value = new ArrayCollection($value);
        }

        $repo = $this->registry->getRepository(Package::class);
        if ($value instanceof Collection) {
            $value = $value->filter(fn (PackagePermission $permission) => $permission->getSelected());
            $value = $value->map(
                function (PackagePermission $permission) use ($repo) {
                    $package = $repo->findOneBy(['name' => $permission->getName()]);
                    if ($package === null) {
                        throw new TransformationFailedException('Unable to find package with name ' . $permission->getName());
                    }

                    $aclPermission =  new GroupAclPermission();
                    $aclPermission->setVersion($permission->getVersion())
                        ->setPackage($package);

                    return $aclPermission;
                }
            );
        }

        return $value;
    }
}
