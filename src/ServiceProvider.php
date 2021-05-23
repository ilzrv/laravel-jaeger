<?php

declare(strict_types=1);

namespace Ilzrv\LaravelJaeger;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Jaeger\Config;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;

final class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    /**
     * Name of the transmitted header
     */
    private const HEADER = 'X-TRACE';

    /**
     * Register services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/tracing.php', 'tracing');
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(): void
    {
        /** @var Repository $config */
        $config = $this->app->make(Repository::class);

        if (!$config->get('tracing.enabled') || !$config->get('tracing.host')) {
            return;
        }

        $tracer = Config::getInstance()->initTracer(
            $config->get('tracing.service.name'),
            $config->get('tracing.host') . ':' . $config->get('tracing.port')
        );

        $this->app->instance('context.tracer', $tracer);

        if ($this->app->runningInConsole()) {
            $spanName = 'console';
            $spanId = 0;
        } else {
            /** @var Request $request */
            $request = $this->app->make(Request::class);
            $spanName = $request->path();

            if ($trace = $request->header(self::HEADER)) {
                $spanContext = unserialize($trace);
                $spanId = $spanContext->spanId;
            } else {
                $spanId = 0;
            }
        }

        if ($spanId !== 0 && isset($spanContext)) {
            $globalSpan = $tracer->startSpan($spanName, ['child_of' => $spanContext]);
        } else {
            $globalSpan = $tracer->startSpan($spanName);
        }

        $this->bindClient(
            serialize($globalSpan->getContext())
        );

        $this->app->instance('context.tracer.globalSpan', $globalSpan);

        $this->app->terminating(
            function () {
                $this->app->get('context.tracer.globalSpan')->finish();
                $this->app->get('context.tracer')->flush();
            }
        );

        $this->registerListeners();
    }

    private function bindClient(string $context): void
    {
        $this->app->bind(
            Client::class,
            function () use ($context) {
                $stack = HandlerStack::create();

                $stack->push(
                    $this->addHeader(self::HEADER, $context)
                );

                return new Client(
                    [
                        'handler' => $stack,
                    ]
                );
            }
        );
    }

    private function addHeader($header, $value): \Closure
    {
        return function (callable $handler) use ($header, $value) {
            return function (
                RequestInterface $request,
                array $options
            ) use ($handler, $header, $value) {
                $request = $request->withHeader($header, $value);
                return $handler($request, $options);
            };
        };
    }

    private function registerListeners(): void
    {
        /** @var Dispatcher $event */
        $event = $this->app->make(Dispatcher::class);

        $event->listen(
            MessageLogged::class,
            function (MessageLogged $e) {
                $this->app->get('context.tracer.globalSpan')->log((array) $e);
            }
        );

        $event->listen(
            RequestHandled::class,
            function (RequestHandled $e) {
                collect(
                    [
                        'user_id' => $e->request->user() ? $e->request->user()->getKey() : '-',
                        'request_host' => $e->request->getHost(),
                        'request_path' => $e->request->path(),
                        'request_method' => $e->request->method(),
                        'response_status' => $e->response->getStatusCode(),
                    ]
                )->each(
                    function ($tag, $key) {
                        $this->app->get('context.tracer.globalSpan')->setTag($key, $tag);
                    }
                );
            }
        );

        /** @var Connection $connection */
        $connection = $this->app->make(Connection::class);

        $connection->listen(
            function ($query) {
                /** @var LoggerInterface $logger */
                $logger = $this->app->make(LoggerInterface::class);

                $logger->debug(
                    "[DB Query] {$query->connection->getName()}",
                    [
                        'query' => $query->sql,
                        'time' => $query->time . 'ms',
                    ]
                );
            }
        );
    }
}
