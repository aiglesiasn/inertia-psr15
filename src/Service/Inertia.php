<?php

declare(strict_types=1);

namespace Cherif\InertiaPsr15\Service;

use Cherif\InertiaPsr15\Model\LazyProp;
use Cherif\InertiaPsr15\Model\Page;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

class Inertia implements InertiaInterface
{
    private ServerRequestInterface $request;
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;
    private RootViewProviderInterface $rootViewProvider;
    private Page $page;

    public function __construct(
        ServerRequestInterface $request, 
        ResponseFactoryInterface $responseFactory, 
        StreamFactoryInterface $streamFactory,
        RootViewProviderInterface $rootViewProvider
    ) {
        $this->request = $request;
        $this->responseFactory = $responseFactory;
        $this->streamFactory = $streamFactory;
        $this->rootViewProvider = $rootViewProvider;
        $this->page = Page::create();
    }

    public function render(string $component, array $props = [], string $url = null): ResponseInterface
    {
        $this->page = $this->page
            ->withComponent($component)
            ->withUrl($url ?? (string)$this->request->getUri());

        if ($this->request->hasHeader('X-Inertia-Partial-Data')) {
            $only = explode(',', $this->request->getHeaderLine('X-Inertia-Partial-Data'));
            $props = ($only && $this->request->getHeaderLine('X-Inertia-Partial-Component') === $component)
            ? array_intersect_key($props, array_flip((array) $only))
            : $props;
        } else {
            $props = array_filter($props, function ($prop) {
                return ! $prop instanceof LazyProp;
            });
        }

        array_walk_recursive($props, function (&$prop) {
            if ($prop instanceof \Closure || $prop instanceof LazyProp ) {
                $prop = $prop();
            }
        });

        $this->page = $this->page->withProps($props);

        if ($this->request->hasHeader('X-Inertia')) {
            $json = json_encode($this->page);
            return $this->createResponse($json, 'application/json');
        }

        $rootViewProvider = $this->rootViewProvider;
        $html = $rootViewProvider($this->page);

        return $this->createResponse($html, 'text/html; charset=UTF-8');
    }

    public function version($version)
    {
        $this->page = $this->page->withVersion($version);
    }

    public function share(string $key, $value = null)
    {
        $this->page = $this->page->addProp($key, $value);
    }

    public function getVersion(): ?string
    {
        return $this->page->getVersion();
    }

    private function createResponse(string $data, string $contentType)
    {
        $stream = $this->streamFactory->createStream($data);
        return $this->responseFactory->createResponse()
                    ->withBody($stream)
                    ->withHeader('Content-Type', $contentType);
    }

    public static function lazy(callable $callable): LazyProp
    {
        return new LazyProp($callable);
    }

    /**
     * @param string|ResponseInterface $destination
     * @param int $status
     * @return ResponseInterface
     */
    public function location($destination, int $status = 302): ResponseInterface
    {
        $response = $this->createResponse('', 'text/html; charset=UTF-8');

        // We check if InertiaMiddleware has set up the 'X-Inertia-Location' header, so we handle the response accordingly
        if ($this->request->hasHeader('X-Inertia')) {
            $response = $response->withStatus(409);
            return $response->withHeader(
                'X-Inertia-Location',
                $destination instanceof ResponseInterface ? $destination->getHeaderLine('Location') : $destination
            );
        }

        if ($destination instanceof ResponseInterface) {
            return $destination;
        }

        $response = $response->withStatus($status);
        return $response->withHeader('Location', $destination);
    }
}
