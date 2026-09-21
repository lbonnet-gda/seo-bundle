<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Http;

use Lbonnet\SeoBundle\Model\PageResponse;
use Lbonnet\SeoBundle\Model\RedirectChain;

interface RedirectChainResolverInterface
{
    /**
     * Follows a redirect whose first response has already been received, hop by hop, until
     * a non-3xx response, a loop, or the follow budget is reached.
     *
     * @param PageResponse $response the 3xx response that starts the chain
     */
    public function resolve(PageResponse $response): RedirectChain;
}
