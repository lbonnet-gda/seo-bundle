<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Module\Technical\Http;

use Lbonnet\SeoBundle\Http\PageFetcher;
use Lbonnet\SeoBundle\Module\Technical\Http\HttpTargetProbe;
use Lbonnet\SeoBundle\Robots\RobotsTxt;
use Lbonnet\SeoBundle\Robots\RobotsTxtProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class HttpTargetProbeTest extends TestCase
{
    public function testDoesNotFetchRobotsTxtWhenProbingIsDisabled(): void
    {
        $provider = $this->createMock(RobotsTxtProviderInterface::class);
        $provider->expects($this->never())->method('robotsTxt');

        $probe = new HttpTargetProbe($this->pageFetcher(), enabled: false, robotsTxtProvider: $provider);

        $this->assertNull($probe->robotsTxt('https://other.com/a'));
    }

    public function testFetchesRobotsTxtOncePerHostWithinTheSharedBudget(): void
    {
        $provider = $this->createMock(RobotsTxtProviderInterface::class);
        $provider->expects($this->once())->method('robotsTxt')->willReturnCallback(
            static fn(string $url): RobotsTxt => RobotsTxt::notFound(
                'https://other.com/robots.txt',
                Response::HTTP_NOT_FOUND,
            ),
        );

        $probe = new HttpTargetProbe($this->pageFetcher(), maxProbes: 2, robotsTxtProvider: $provider);

        $this->assertNotNull($probe->robotsTxt('https://other.com/a'));
        $this->assertNotNull($probe->robotsTxt('https://OTHER.com/b'));
        $this->assertNotNull($probe->probe('https://third.com/'));
        $this->assertNull($probe->robotsTxt('https://fourth.com/'));
        $this->assertNull($probe->probe('https://fifth.com/'));
    }

    public function testAUrlThatNeverAnswersComesBackWithNoStatus(): void
    {
        $httpClient = new MockHttpClient(
            static fn(): MockResponse => new MockResponse('', ['error' => 'Could not resolve host'])
        );

        $response = (new HttpTargetProbe(new PageFetcher($httpClient)))->probe('https://typo-domain.example/page');

        $this->assertNotNull($response);
        $this->assertSame(HttpTargetProbe::NO_ANSWER, $response->statusCode);
    }

    public function testResetForgetsTheFetchedRobotsTxt(): void
    {
        $provider = $this->createMock(RobotsTxtProviderInterface::class);
        $provider->expects($this->exactly(2))->method('robotsTxt')->willReturn(
            RobotsTxt::notFound('https://other.com/robots.txt', Response::HTTP_NOT_FOUND),
        );

        $probe = new HttpTargetProbe($this->pageFetcher(), maxProbes: 1, robotsTxtProvider: $provider);

        $probe->robotsTxt('https://other.com/a');
        $probe->reset();

        $this->assertNotNull($probe->robotsTxt('https://other.com/a'));
    }

    private function pageFetcher(): PageFetcher
    {
        return new PageFetcher(
            new MockHttpClient(static fn(): MockResponse => new MockResponse('', ['http_code' => Response::HTTP_OK]))
        );
    }
}
