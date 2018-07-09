<?php

namespace Packagist\WebBundle\Model;

use Composer\Spdx\SpdxLicenses;

class ValidatingArrayLoader extends \Composer\Package\Loader\ValidatingArrayLoader
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
                };
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
