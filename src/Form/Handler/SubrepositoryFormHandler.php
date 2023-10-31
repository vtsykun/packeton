<?php

declare(strict_types=1);

namespace Packeton\Form\Handler;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\CacheInterface;

class SubrepositoryFormHandler
{
    public function __construct(
        protected ManagerRegistry $registry,
        protected CacheInterface $cache,
    ) {
    }

    public function handle(Request|array $request, FormInterface $form, bool $patch = false): bool
    {
        if ($request instanceof Request) {
            $form->handleRequest($request);
        } else {
            $form->submit($request, !$patch);
        }

        $em = $this->registry->getManager();
        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->deleteCache();
            $em->persist($entity);
            $em->flush();
            return true;
        }

        return false;
    }

    public function deleteCache(): void
    {
        $this->cache->delete('sub_repos_list');
    }
}
