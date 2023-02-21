<?php

declare(strict_types=1);

namespace Packeton\Mirror\Model;

interface ApprovalRepoInterface
{
    /**
     * Get list of approved package for this repo
     *
     * @return array
     */
    public function getApproved(): array;

    /**
     * @return array
     */
    public function getSettings(): array;

    /**
     * Remove approve for package.
     *
     * @param string $name
     * @return void
     */
    public function removeApprove(string $name): void;

    /**
     * Approve for a package.
     *
     * @param string $name
     * @return void
     */
    public function markApprove(string $name): void;

    /**
     * This repo have a strict mode.
     *
     * @return array
     */
    public function requireApprove(): bool;
}
