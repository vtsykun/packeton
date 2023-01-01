<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Packeton\Form\Type;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\User;
use Packeton\Form\Model\MaintainerRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class AddMaintainerRequestType extends AbstractType
{
    public function __construct(private readonly ManagerRegistry $registry)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('user');
        $builder->get('user')
            ->addModelTransformer(new CallbackTransformer(
                function ($user) {
                    if ($user instanceof User) {
                        return $user->getUsername();
                    }
                    return null;
                },
                function ($username) {
                    if (empty($username)) {
                        return null;
                    }

                    if (!$user = $this->registry->getRepository(User::class)->findOneBy(['username' => $username])) {
                        throw new TransformationFailedException("User $username not found");
                    }
                    return $user;
                }
            ));
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => MaintainerRequest::class,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix()
    {
        return 'add_maintainer_form';
    }
}
