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
#[ORM\Table(name: 'link_require_dev')]
#[ORM\Index(columns: ['version_id', 'packageName'], name: 'link_require_dev_package_name_idx')]
#[ORM\Index(columns: ['packageName'], name: 'link_require_dev_name_idx')]
class DevRequireLink extends PackageLink
{
    #[ORM\ManyToOne(targetEntity: Version::class, inversedBy: 'devRequire')]
    protected ?Version $version = null;
}
