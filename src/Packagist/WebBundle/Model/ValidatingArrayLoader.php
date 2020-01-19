<?php

namespace Packagist\WebBundle\Model;

use Composer\Spdx\SpdxLicenses;
use Composer\Package\Loader\ValidatingArrayLoader as ComposerValidatingArrayLoader;

class ValidatingArrayLoader extends ComposerValidatingArrayLoader
{
    /**
     * {@inheritdoc}
     */
    public function load(array $config, $class = 'Composer\Package\CompletePackage')
    {
        if (isset($config['license'])) {
            $licenses = (array) $config['license'];
            $licenseValidator = new SpdxLicenses();
            foreach ($licenses as $key => $license) {
                if ('proprietary' === $license || !$licenseValidator->validate($license)) {
                    unset($licenses[$key]);
                }
            }

            if (!$licenses) {
                unset($config['license']);
            } else {
                $config['license'] = \count($licenses) === 1 ? \reset($licenses) : $licenses;
            }
        }

        return parent::load($config, $class);
    }
}
