<?php

namespace React\HttpClient;

use Evenement\EventEmitter;
use Guzzle\Http\Message\Request as GuzzleRequest;
use Guzzle\Http\Url;
use Guzzle\Parser\Message\MessageParser;
use React\EventLoop\LoopInterface;
use React\HttpClient\ConnectionManagerInterface;
use React\HttpClient\Response;
use React\HttpClient\ResponseHeaderParser;
use React\Stream\Stream;
use React\Stream\WritableStreamInterface;

class Request extends EventEmitter implements WritableStreamInterface
{
    private $request;
    private $loop;
    private $connectionManager;
    private $stream;
    private $buffer;
    private $responseFactory;
    private $response;
    private $writable;

    private $writingHead;

    public function __construct(LoopInterface $loop, ConnectionManagerInterface $connectionManager, GuzzleRequest $request)
    {
        $this->loop = $loop;
        $this->connectionManager = $connectionManager;
        $this->request = $request;
        $this->writable = true;
    }

    public function isWritable()
    {
        return $this->writable;
    }

    public function writeHead()
    {
        if ($this->writingHead) {
            return $this->writingHead;
        }

        $that = $this;
        $request = $this->request;
        $streamRef = &$this->stream;

        $this->writingHead = $this->connect()->then(function ($stream) use ($that, $request, &$streamRef) {
            $streamRef = $stream;

            $stream->on('drain', array($that, 'handleDrain'));
            $stream->on('data', array($that, 'handleData'));
            $stream->on('end', array($that, 'handleEnd'));
            $stream->on('error', array($that, 'handleError'));

            $request->setProtocolVersion('1.0');
            $headers = (string) $request;

            $stream->write($headers);

            return $stream;

        }, function ($error) use ($that) {
            $that->closeError(new \RuntimeException(
                "Connection failed",
                0,
                $error
            ));
        });

        return $this->writingHead;
    }

    public function write($data)
    {
        $this->writeHead()->then(function($stream) use ($data) {
            if (null === $data) {
                return;
            }
            $stream->write($data);
        });
    }

    public function end($data = null)
    {
        if (null !== $data && !is_scalar($data)) {
            throw new \InvalidArgumentException('$data must be null or scalar');
        }

        $this->write($data);
    }

    public function handleDrain()
    {
        $this->emit('drain', array($this));
    }

    public function handleData($data)
    {
        $this->buffer .= $data;

        if (false !== strpos($this->buffer, "\r\n\r\n")) {
            list($response, $bodyChunk) = $this->parseResponse($this->buffer);

            $this->buffer = null;

            $this->stream->removeListener('drain', array($this, 'handleDrain'));
            $this->stream->removeListener('data', array($this, 'handleData'));
            $this->stream->removeListener('end', array($this, 'handleEnd'));
            $this->stream->removeListener('error', array($this, 'handleError'));

            $this->response = $response;
            $that = $this;

            $response->on('end', function () use ($that) {
                $that->close();
            });
            $response->on('error', function (\Exception $error) use ($that) {
                $that->closeError(new \RuntimeException(
                    "An error occured in the response",
                    0,
                    $error
                ));
            });

            $this->emit('response', array($response, $this));

            $response->emit('data', array($bodyChunk));
        }
    }

    public function handleEnd()
    {
        $this->closeError(new \RuntimeException(
            "Connection closed before receiving response"
        ));
    }

    public function handleError($error)
    {
        $this->closeError(new \RuntimeException(
            "An error occurred in the underlying stream",
            0,
            $error
        ));
    }

    public function closeError(\Exception $error)
    {
        if (!$this->writable) {
            return;
        }
        $this->emit('error', array($error, $this));
        $this->close($error);
    }

    public function close(\Exception $error = null)
    {
        if (!$this->writable) {
            return;
        }

        $this->writable = false;

        if ($this->stream) {
            $this->stream->close();
        }

        $this->emit('end', array($error, $this->response, $this));
    }

    protected function parseResponse($data)
    {
        $parser = new MessageParser();
        $parsed = $parser->parseResponse($data);

        $factory = $this->getResponseFactory();

        $response = $factory(
            $parsed['protocol'],
            $parsed['version'],
            $parsed['code'],
            $parsed['reason_phrase'],
            $parsed['headers']
        );

        return array($response, $parsed['body']);
    }

    protected function connect()
    {
        $host = $this->request->getHost();
        $port = $this->request->getPort();
        return $this->connectionManager->getConnection($host, $port);
    }

    public function setResponseFactory($factory)
    {
        $this->responseFactory = $factory;
    }

    public function getResponseFactory()
    {
        if (null === $factory = $this->responseFactory) {
            $loop = $this->loop;
            $stream = $this->stream;

            $factory = function ($protocol, $version, $code, $reasonPhrase, $headers) use ($loop, $stream) {
                return new Response(
                    $loop,
                    $stream,
                    $protocol,
                    $version,
                    $code,
                    $reasonPhrase,
                    $headers
                );
            };

            $this->responseFactory = $factory;
        }

        return $factory;
    }
}

