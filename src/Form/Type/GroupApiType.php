<?php

declare(strict_types=1);

namespace Packeton\Form\Type;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\GroupAclPermission;
use Packeton\Entity\Package;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class GroupApiType extends AbstractType
{
    public function __construct(
        protected ManagerRegistry $registry
    ){
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->remove('aclPermissions');

        $builder->add('aclPermissions', UnstructuredType::class);

        $fun = function ($items) {
            return null;
        };

        $builder->get('aclPermissions')
            ->resetModelTransformers()
            ->resetViewTransformers()
            ->addModelTransformer(new CallbackTransformer($fun, function ($value) {
                if (empty($value) || !\is_array($value)) {
                    return null;
                }

                $repo = $this->registry->getRepository(Package::class);
                $permissions = new ArrayCollection();
                foreach ($value as $item) {
                    $version = $package = null;
                    if (is_array($item)) {
                        if (!isset($item['name'])) {
                            throw new TransformationFailedException('Invalid data format. Name is required: {"name": "pkg", "version": null}');
                        }
                        $version = $item['version'] ?? null;
                        $item = $item['name'];
                    }

                    if (is_string($item)) {
                        $package = $repo->findOneBy(['name' => $item]);
                    }

                    if ($package === null) {
                        throw new TransformationFailedException("Unable to find package with name:" . (is_string($item) ? $item : ''));
                    }
                    $acl = new GroupAclPermission();
                    $acl->setPackage($package)
                        ->setVersion($version);
                    $permissions->add($acl);
                }

                return $permissions;
            }));
    }

    public function getParent(): string
    {
        return GroupType::class;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('csrf_protection', false);
    }
}
