<?php

namespace React\HttpClient;

use React\EventLoop\LoopInterface;
use React\Stream\Stream;
use React\Dns\Resolver\Resolver;
use React\Promise\Deferred;
use React\Promise\Util;

class ConnectionManager implements ConnectionManagerInterface
{
    protected $loop;
    protected $resolver;

    public function __construct(LoopInterface $loop, Resolver $resolver)
    {
        $this->loop = $loop;
        $this->resolver = $resolver;
    }

    public function getConnection($host, $port)
    {
        $that = $this;

        return $this->resolve($host)
            ->then(function ($address) use ($that, $port) {
                return $that->getConnectionForAddress($address, $port);
            }, function ($error) use ($host) {
                throw new \RuntimeException(
                    sprintf("failed to resolve %s", $host),
                    0,
                    $error
                );
            });
    }

    public function getConnectionForAddress($address, $port)
    {
        $url = $this->getSocketUrl($address, $port);

        $socket = stream_socket_client($url, $errno, $errstr, ini_get("default_socket_timeout"), STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);

        if (!$socket) {
            return Util::reject(new \RuntimeException(
                sprintf("connection to %s:%d failed: %s", $address, $port, $errstr),
                $errno
            ));
        }

        stream_set_blocking($socket, 0);

        // wait for connection

        $loop = $this->loop;
        $that = $this;
        $deferred = new Deferred;

        $this->loop->addWriteStream($socket, function () use ($that, $deferred, $socket, $loop) {

            $loop->removeWriteStream($socket);

            $that->handleConnectedSocket($socket)->then(array($deferred, 'resolve'), array($deferred, 'reject'));
        });

        return $deferred->promise();
    }

    public function handleConnectedSocket($socket)
    {
        return Util::resolve(new Stream($socket, $this->loop));
    }

    protected function getSocketUrl($host, $port)
    {
        return sprintf('tcp://%s:%s', $host, $port);
    }

    protected function resolve($host)
    {
        if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
            return Util::resolve($host);
        }

        $deferred = new Deferred;
        $this->resolver->resolve($host, array($deferred, 'resolve'), array($deferred, 'reject'));

        return $deferred->promise();
    }
}

