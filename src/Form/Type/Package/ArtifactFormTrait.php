<?php

declare(strict_types=1);

namespace Packeton\Form\Type\Package;

use Packeton\Entity\Zipball;
use Packeton\Util\PacketonUtils;

trait ArtifactFormTrait
{
    protected function getChoices(bool $unsetUsed = false): array
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
