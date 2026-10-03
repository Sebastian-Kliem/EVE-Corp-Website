<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\SameOriginRequestSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class SameOriginRequestSubscriberTest extends TestCase
{
    private SameOriginRequestSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->subscriber = new SameOriginRequestSubscriber();
    }

    public function testSafeMethodsAreAllowed(): void
    {
        $request = $this->_createRequest('GET', ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        $this->assertTrue($this->subscriber->isAllowed($request));
    }

    public function testSameOriginPostIsAllowed(): void
    {
        $request = $this->_createRequest('POST', ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        $this->assertTrue($this->subscriber->isAllowed($request));
    }

    public function testCrossSiteAndSameSitePostsAreRejected(): void
    {
        $this->assertFalse($this->subscriber->isAllowed($this->_createRequest('POST', ['HTTP_SEC_FETCH_SITE' => 'cross-site'])));
        $this->assertFalse($this->subscriber->isAllowed($this->_createRequest('DELETE', ['HTTP_SEC_FETCH_SITE' => 'same-site'])));
    }

    public function testOriginHeaderIsUsedAsFallback(): void
    {
        $this->assertTrue($this->subscriber->isAllowed($this->_createRequest('POST', ['HTTP_ORIGIN' => 'https://keepers-of-duat.de'])));
        $this->assertFalse($this->subscriber->isAllowed($this->_createRequest('POST', ['HTTP_ORIGIN' => 'https://wanderer.keepers-of-duat.de'])));
        $this->assertFalse($this->subscriber->isAllowed($this->_createRequest('POST', ['HTTP_ORIGIN' => 'null'])));
    }

    public function testBearerAndNonBrowserRequestsAreAllowed(): void
    {
        $this->assertTrue($this->subscriber->isAllowed($this->_createRequest('POST', [
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
            'HTTP_AUTHORIZATION' => 'Bearer token',
        ])));
        $this->assertTrue($this->subscriber->isAllowed($this->_createRequest('POST', [])));
    }

    private function _createRequest(string $method, array $server): Request
    {
        return Request::create('https://keepers-of-duat.de/corp/tracking/api/lists', $method, [], [], [], $server);
    }
}
