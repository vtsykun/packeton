<?php

declare(strict_types=1);

namespace Packeton\Event;

use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\EventDispatcher\Event;

class FormHandlerEvent extends Event
{
    public const NAME  = 'formHandler';

    public function __construct(protected FormInterface $form, protected string $entityClass)
    {
    }

    /**
     * @return FormInterface
     */
    public function getForm(): FormInterface
    {
        return $this->form;
    }

    /**
     * @return string
     */
    public function getEntityClass(): string
    {
        return $this->entityClass;
    }
}
