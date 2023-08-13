<?php

declare(strict_types=1);

namespace Packeton\Form\Type\Package;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Package;
use Packeton\Entity\Zipball;
use Packeton\Form\Handler\ArtifactHandler;
use Packeton\Util\PacketonUtils;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

class ArtifactPackageType extends AbstractType
{
    use ArtifactFormTrait;

    public function __construct(
        protected ManagerRegistry $registry,
        protected ArtifactHandler $handler,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->remove('credentials');
        $builder->remove('pullRequestReview');

        $builder
            ->add('repositoryPath', TextType::class, [
                'label' => 'Path (optional)',
                'required' => false,
                'attr'  => [
                    'class' => 'package-repo-info',
                    'placeholder' => 'e.g. /data/artifacts/package',
                ]
            ])
            ->add('archives', ChoiceType::class, [
                'required' => false,
                'multiple' => true,
                'label' => 'Zipball Assets (if path is empty)',
                'choices' => $this->getChoices($options['is_created']),
                'attr'  => ['class' => 'jselect2 archive-select']
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->updateRepository(...), 255);
        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->setUsageFlag(...), -255);
    }
}
