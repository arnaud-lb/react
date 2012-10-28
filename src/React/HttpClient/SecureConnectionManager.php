<?php

namespace React\HttpClient;

use React\EventLoop\LoopInterface;
use React\Stream\Stream;
use React\Promise\Deferred;

class SecureConnectionManager extends ConnectionManager
{
    public function handleConnectedSocket($socket)
    {
        $deferred = new Deferred;
        $loop = $this->loop;

        $enableCrypto = function () use ($socket, $loop, $deferred) {

            $result = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

            if (true === $result) {
                // crypto was successfully enabled
                $loop->removeWriteStream($socket);
                $loop->removeReadStream($socket);
                $deferred->resolve(new Stream($socket, $loop));

            } else if (false === $result) {
                // an error occured
                $loop->removeWriteStream($socket);
                $loop->removeReadStream($socket);
                $deferred->reject(new \RuntimeException("error occured while enabling crypto"));

            } else {
                // need more data, will retry
            }
        };

        $this->loop->addWriteStream($socket, $enableCrypto);
        $this->loop->addReadStream($socket, $enableCrypto);

        $enableCrypto();

        return $deferred->promise();
    }
}


