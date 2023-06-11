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

        $builder->remove('pullRequestReview');

        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->updateRepository(...), 255);
        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->setUsageFlag(...), -255);
    }

    public function updateRepository(FormEvent $event): void
    {
        $package = $event->getData();
        if ($package instanceof Package) {
            $this->handler->updatePackageUrl($package);
        }
    }

    public function setUsageFlag(FormEvent $event): void
    {
        $package = $event->getData();
        if (!$package instanceof Package) {
            return;
        }
        $errors = $event->getForm()->getErrors(true);
        if (count($errors) !== 0) {
            return;
        }

        $repo = $this->registry->getRepository(Zipball::class);
        foreach ($package->getArchives() ?: [] as $archive) {
            // When form was submitted and called flush
            $repo->find($archive)?->setUsed(true);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getParent(): string
    {
        return BasePackageType::class;
    }

    protected function getChoices(bool $unsetUsed): array
    {
        $choices = [];
        $all = $this->registry->getRepository(Zipball::class)->ajaxSelect($unsetUsed);
        foreach ($all as $item) {
            $label = $item['filename'] . ' ('.  PacketonUtils::formatSize($item['size']) . ')';
            $choices[$label] = $item['id'];
        }

        return $choices;
    }
}
