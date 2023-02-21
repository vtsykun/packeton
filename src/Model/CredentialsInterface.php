<?php

declare(strict_types=1);

namespace Packeton\Model;

interface CredentialsInterface
{
    /**
     * SSH Key
     *
     * @return string
     */
    public function getKey(): ?string;

    /**
     * SSH Key file
     *
     * @return string|null
     */
    public function getPrivkeyFile(): ?string;

    /**
     * @return array|null
     */
    public function getComposerConfig() :?array;

    /**
     * @param string $name
     *
     * @return mixed
     */
    public function getComposerConfigOption(string $name): mixed;
}
