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
#[ORM\Table(name: 'link_suggest')]
#[ORM\Index(columns: ['version_id', 'packageName'], name: 'link_suggest_package_name_idx')]
#[ORM\Index(columns: ['packageName'], name: 'link_suggest_name_idx')]
class SuggestLink extends PackageLink
{
    #[ORM\ManyToOne(targetEntity: 'Packeton\Entity\Version', inversedBy: 'suggest')]
    protected ?Version $version = null;
}
