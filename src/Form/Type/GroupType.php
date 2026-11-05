<?php

namespace Packeton\Form\Type;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Group;
use Packeton\Entity\User;
use Packeton\Mirror\ProxyRepositoryRegistry;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

class GroupType extends AbstractType
{
    public function __construct(
        private readonly ProxyRepositoryRegistry $registry,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly ManagerRegistry $doctrine,
        private readonly ParameterBagInterface $parameterBag,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isAdmin = $options['is_admin'];

        $builder
            ->add('name', TextType::class, ['label' => 'Name', 'constraints' => [new NotBlank()]]);

        if ($isAdmin) {
            $builder
                ->add('expiredUpdatesAt', DateType::class, [
                    'widget' => 'single_text',
                    'required' => false,
                    'label' => 'Update expiration',
                    'tooltip' => 'A new release updates will be frozen after this date. But the user can uses the versions released before.'
                ]);

            $proxyChoice = $this->registry->getAllNames();
            $proxyChoice = \array_combine($proxyChoice, $proxyChoice);

            if ($proxyChoice) {
                $builder
                    ->add('proxies', ChoiceType::class, [
                        'choices' => $proxyChoice,
                        'multiple' => true,
                        'label' => 'Allowed Proxies',
                        'required' => false
                    ]);
            }

            if ($this->parameterBag->get('packeton.allow_maintainer_group_creation')) {
                $allUsers = $this->doctrine->getRepository(User::class)->findAll();
                $maintainerUsers = array_values(array_filter(
                    $allUsers,
                    fn(User $u) => $u->hasRole('ROLE_MAINTAINER')
                ));

                $builder
                    ->add('owners', EntityType::class, [
                        'class' => User::class,
                        'choice_label' => 'username',
                        'multiple' => true,
                        'required' => false,
                        'label' => 'Owners',
                        'choices' => $maintainerUsers,
                        'attr' => ['class' => 'jselect2'],
                    ]);
            }
        }

        $aclPermissionOptions = [];
        if (!$isAdmin && $user = $this->getUser()) {
            $ownedPackageIds = [];
            foreach ($user->getOwnedGroups() as $group) {
                foreach ($group->getAclPermissions() as $permission) {
                    if ($permission->getPackage() !== null) {
                        $ownedPackageIds[] = $permission->getPackage()->getId();
                    }
                }
            }
            foreach ($user->getPackages() as $package) {
                $ownedPackageIds[] = $package->getId();
            }
            $aclPermissionOptions['allowed_packages'] = array_values(array_unique($ownedPackageIds));
        }

        $builder
            ->add('aclPermissions', GroupAclPermissionCollectionType::class, $aclPermissionOptions);
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Group::class,
            'is_admin' => true,
        ]);
    }

    private function getUser(): ?User
    {
        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return null;
        }

        $user = $token->getUser();

        return $user instanceof User ? $user : null;
    }
}
