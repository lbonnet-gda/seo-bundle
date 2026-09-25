<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module;

use Lbonnet\SeoBundle\Model\Module;

final class ModuleOptions
{
    /**
     * @param list<Module> $modules the modules running alongside, so a module can leave to another what it covers
     */
    public function __construct(
        public readonly bool $checkExternal = true,
        public readonly array $modules = [],
    ) {
    }

    public function runs(Module $module): bool
    {
        return in_array($module, $this->modules, true);
    }
}
