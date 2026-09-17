<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Model;

enum Module: string
{
    case Links = 'links';
    case OnPage = 'on_page';
    case Technical = 'technical';
}
