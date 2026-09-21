<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module;

final class ModuleOptions
{
    public function __construct(
        public readonly bool $checkExternal = true,
    ) {
    }
}
