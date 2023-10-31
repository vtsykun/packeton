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

namespace Packeton\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'link_provide')]
class ProvideLink extends PackageLink
{
    #[ORM\ManyToOne(targetEntity: 'Packeton\Entity\Version', inversedBy: 'provide')]
    protected ?Version $version = null;
}
