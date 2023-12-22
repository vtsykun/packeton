<?php

declare(strict_types=1);

namespace Packeton\Event;

use Knp\Menu\ItemInterface;
use Symfony\Contracts\EventDispatcher\Event;

class MenuLoadEvent extends Event
{
    public const NAME  = 'menuLoad';

    public function __construct(private readonly ItemInterface $menu, private readonly string $name)
    {
    }

    /**
     * @return ItemInterface
     */
    public function getMenu(): ItemInterface
    {
        return $this->menu;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }
}
