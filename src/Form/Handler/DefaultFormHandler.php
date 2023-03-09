<?php

declare(strict_types=1);

namespace Packeton\Form\Handler;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

class DefaultFormHandler
{
    public function __construct(
        protected ManagerRegistry $registry,
    ){
    }

    public function handle(Request|array $request, FormInterface $form, bool $patch = false, callable $onSuccess = null): bool
    {
        if ($request instanceof Request) {
            $form->handleRequest($request);
        } else {
            $form->submit($request, !$patch);
        }

        $em = $this->registry->getManager();
        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            if (null !== $onSuccess) {
                $onSuccess($entity, $form);
            }

            $em->persist($entity);
            $em->flush();
            return true;
        }

        return false;
    }
}
