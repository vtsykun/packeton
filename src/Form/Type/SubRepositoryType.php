<?php

declare(strict_types=1);

namespace Packeton\Form\Type;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Package;
use Packeton\Entity\SubRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class SubRepositoryType extends AbstractType
{
    protected $reservedNames = [
        'api', 'jobs', 'explorepopular', 'explore', 'feeds', 'groups', 'mirror',
        'integration', 'oauth2', 'packages', 'versions', 'p', 'p1', 'p2', 'metadata',
        'proxies', 'login', 'reset-password', 'logout', 'subrepository', 'profile',
        'users', 'change-password', 'search', 'statistics', 'webhooks', 'archive',
        'zipball', 'about', 'about-composer', 'providers'
    ];

    public function __construct(protected ManagerRegistry $registry)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $names = $this->registry->getRepository(Package::class)->getPackageNames();
        $names = array_combine($names, $names);

        $builder
            ->add('name', TextType::class)
            ->add('slug', TextType::class, [
                'constraints' => [new NotBlank(), new Regex('/^[a-zA-Z0-9\-_]+$/'), new Callback($this->checkSlug(...))],
                'attr' => ['placeholder' => 'repo1'],
                'label' => 'Urls slug'
            ])
            ->add('urls', TextareaType::class, [
                'required' => false,
                'label' => 'Subdomain or separate hostname',
                'attr' => ['placeholder' => "e.g.: repo1.example.com\nrepo2.example.com", 'rows' => 4]
            ])
            ->add('packages', ChoiceType::class, [
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => $names
            ]);
    }

    public function checkSlug($value, ExecutionContextInterface $context): void
    {
        if (!$value) {
            return;
        }

        if (preg_match('/^[-_]|[-_]$/', $value)) {
            $context->addViolation('The slug can not start with -_');
        }

        if (in_array($value, $this->reservedNames)) {
            $context->addViolation('This name is reserved');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SubRepository::class,
        ]);
    }
}
