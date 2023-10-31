<?php

namespace Packeton\Menu;

use Knp\Menu\FactoryInterface;
use Knp\Menu\ItemInterface;
use Packeton\Entity\User;
use Packeton\Integrations\IntegrationRegistry;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class MenuBuilder
{
    public function __construct(
        private readonly FactoryInterface $factory,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly TranslatorInterface $translator,
        private readonly AuthorizationCheckerInterface $checker,
        private readonly IntegrationRegistry $integrations,
    ) {
    }

    public function createUserMenu()
    {
        $menu = $this->factory->createItem('root');
        $menu->setChildrenAttribute('class', 'list-unstyled');

        $this->addProfileMenu($menu);
        $menu->addChild('hr', ['label' => 'menu.break_line_icon', 'labelAttributes' => ['class' => 'normal'], 'extras' => ['safe_label' => true]]);
        $menu->addChild($this->translator->trans('menu.logout'), ['label' => 'menu.logout_icon', 'route' => 'logout', 'extras' => ['safe_label' => true]]);

        return $menu;
    }

    public function createProfileMenu()
    {
        $menu = $this->factory->createItem('root');
        $menu->setChildrenAttribute('class', 'nav nav-tabs nav-stacked');

        $this->addProfileMenu($menu);

        return $menu;
    }

    public function createAdminMenu()
    {
        $menu = $this->factory->createItem('root');
        $menu->setChildrenAttribute('class', 'list-unstyled');

        $menu->addChild($this->translator->trans('menu.my_users'), ['label' => 'menu.my_users_icon', 'route' => 'users_list', 'extras' => ['safe_label' => true]]);
        $menu->addChild($this->translator->trans('menu.my_groups'), ['label' => 'menu.my_groups_icon', 'route' => 'groups_index', 'extras' => ['safe_label' => true]]);
        $menu->addChild($this->translator->trans('menu.ssh_keys'), ['label' => 'menu.ssh_keys_icon', 'route' => 'user_add_sshkey', 'extras' => ['safe_label' => true]]);
        $menu->addChild($this->translator->trans('menu.webhooks'), ['label' => 'menu.webhooks_icon', 'route' => 'webhook_index', 'extras' => ['safe_label' => true]]);
        $menu->addChild($this->translator->trans('menu.proxies'), ['label' => 'menu.proxies_icon', 'route' => 'proxies_list', 'extras' => ['safe_label' => true]]);
        $menu->addChild($this->translator->trans('menu.subrepository'), ['label' => 'menu.subrepository_icon', 'route' => 'subrepository_index', 'extras' => ['safe_label' => true]]);
        if ($this->integrations->getNames()) {
            $menu->addChild($this->translator->trans('menu.integrations'), ['label' => 'menu.integrations_icon', 'route' => 'integration_list', 'extras' => ['safe_label' => true]]);
        }

        return $menu;
    }

    private function addProfileMenu(ItemInterface $menu)
    {
        $user = $this->tokenStorage->getToken() ? $this->tokenStorage->getToken()->getUser() : null;
        $menu->addChild($this->translator->trans('menu.profile'), ['label' => 'menu.profile_icon', 'route' => 'profile_show', 'extras' => ['safe_label' => true]]);

        if ($user instanceof User) {
            $menu->addChild($this->translator->trans('menu.settings'), ['label' => 'menu.settings_icon', 'route' => 'profile_edit', 'extras' => ['safe_label' => true]]);
            $menu->addChild($this->translator->trans('menu.change_password'), ['label' => 'menu.change_password_icon', 'route' => 'change_password', 'extras' => ['safe_label' => true]]);
            $menu->addChild($this->translator->trans('menu.my_tokens'), ['label' => 'menu.my_tokens_icon', 'route' => 'profile_list_tokens', 'extras' => ['safe_label' => true]]);
            $menu->addChild($this->translator->trans('menu.my_login_attempts'), ['label' => 'menu.my_login_icon', 'route' => 'profile_login_attempts', 'extras' => ['safe_label' => true]]);

            if ($this->checker->isGranted('ROLE_MAINTAINER')) {
                $menu->addChild($this->translator->trans('menu.my_packages'), ['label' => 'menu.my_packages_icon', 'route' => 'user_packages', 'routeParameters' => ['name' => $this->getUsername()], 'extras' => ['safe_label' => true]]);
                $menu->addChild($this->translator->trans('menu.my_favorites'), ['label' => 'menu.my_favorites_icon', 'route' => 'user_favorites', 'routeParameters' => ['name' => $this->getUsername()], 'extras' => ['safe_label' => true]]);
            }
        } else if ($user instanceof UserInterface) {
            $menu->addChild($this->translator->trans('menu.my_tokens'), ['label' => 'menu.my_tokens_icon', 'route' => 'profile_list_tokens', 'extras' => ['safe_label' => true]]);
        }
    }

    private function getUsername()
    {
        if ($this->tokenStorage->getToken() && $this->tokenStorage->getToken()->getUser()) {
            return $this->tokenStorage->getToken()->getUser()->getUserIdentifier();
        }

        return null;
    }
}
