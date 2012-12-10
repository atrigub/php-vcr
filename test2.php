<?php

// Objekte:
//  - VCR (configuration, cassettes, proxy, libraryHooks)
//  - Configuration (hooks, cassette_folder, proxyUrl)
//  - Cassette (name, interactions)
//  - Proxy
//  - LibraryHooks: StreamWrapper, Curl?, Soap, Zlib
//  - HTTPInteraction (request, response)
//  - Request (host, port, header, body) -> Symfony?
//  - Response (header, body, serializable) -> Symfony?


/**
 * Factory.
 */
class VCR
{
    public static $isOn = false;
    private $cassette;
    private $config;
    private $proxy;
    private $libraryHooks = array();

    public function __construct(Configuration $config)
    {
        $this->config = $config;
    }

    public function turnOn()
    {
        $this->proxy = $this->createProxy();
        $this->proxy->start();

        $this->libraryHooks = $this->createLibraryHooks();

        self::$isOn = true;
    }

    public function turnOff()
    {
        $this->proxy->stop();
        foreach ($this->libraryHooks as $hook) {
            $hook->disable();
        }
        self::$isOn = false;
    }

    public function insertCassette($cassetteName)
    {
        $this->cassette = $cassetteName;
    }

    public function handleConnection($connection)
    {
        if (empty($this->cassette)) {
            throw new BadMethodCallException('Invalid http request. No cassette inserted.');
        }
        $path = $this->config->getCassettePath() . DIRECTORY_SEPARATOR . $this->cassette;

        if (file_exists($path)) {
            // playback
            $response = file_get_contents($path);
            echo 'playback';
        } else {
            // record
            $request = stream_get_contents($connection);
            $requestId = sha1($request);
            preg_match('/(CONNECT|Host:) (.*)(\s|\\r)/iU', $request, $matches);
            $host = $matches[2];
            $fp = fsockopen($host, 80);
            fwrite($fp, $request);
            $response = stream_get_contents($fp);
            echo 'record';
            file_put_contents($path, $response);
        }

        fwrite($connection, $response);

        // $request = Request::fromString(stream_get_contents($connection));
        // $cassette = $this->getCurrentCassette();

        // if ($cassette->hasResponse($request)) {
        //     $cassette->playback($request);
        //     fwrite($connection, $cassette->getResponse($request));
        // } else {
        //     $response = $this->proxy->executeRequest($request);
        //     $cassette->recordInteraction(new HTTPInteraction($request, $response));
        // }
    }

    private function createProxy()
    {
        $that = $this;
        return new Proxy(
            $this->config->getProxySocket(),
            function ($connection) use ($that) {
                $that->handleConnection($connection);
            }
        );
    }

    private function createLibraryHooks()
    {
        $hooks = array();
        foreach ($this->config->getLibraryHooks() as $hookName) {
            $hooks[] = new $hookName($this->config);
            end($hooks)->enable();
        }
        return $hooks;
    }

    public function __destruct()
    {
        $this->turnOff();
    }

}


/**
 * Configuration.
 */
class Configuration
{
    private $cassettePath = 'fixtures';
    private $proxySocket = 'tcp://0.0.0.0:8000';
    private $libraryHooks = array(
        'StreamWrapper'
    );

    public function getCassettePath()
    {
        return $this->cassettePath;
    }

    public function getLibraryHooks()
    {
        return $this->libraryHooks;
    }

    public function getProxySocket()
    {
        return $this->proxySocket;
    }
}

/**
 * Proxy.
 */
class Proxy
{
    private $socketPath;
    private $socket;
    private $callback;

    public function __construct($socketPath, \closure $callback)
    {
        $this->socketPath = $socketPath;
        $this->callback = $callback;
    }

    public function start()
    {
        // setup IPC (inter process communication)
        $sockets = array();
        if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets)) {
            die(socket_strerror(socket_last_error()));
        }
        list($writer, $reader) = $sockets;

        // fork proxy
        $this->proxyPid = pcntl_fork();
        $this->pid = posix_getpid();

        if ($this->proxyPid == -1) {
            die('could not fork');
        } else if ($this->proxyPid) {
            var_dump('master pid: ' . posix_getpid());
            // wait for proxy
            socket_read($reader, 1024, PHP_NORMAL_READ);
            socket_close($reader);
            socket_close($writer);
        } else {
            var_dump('child pid: ' . posix_getpid());
            // start proxy
            $socket = stream_socket_server($this->socketPath, $errno, $errstr);
            stream_set_blocking($socket, false);

            // notify master
            if (!socket_write($writer, "started\n", strlen("started\n"))) {
                die(socket_strerror(socket_last_error()));
            }

            if (!$socket) {
                echo "$errstr ($errno)<br />\n";
            } else {
                while ($conn = stream_socket_accept($socket)) {
                    $callback = $this->callback;
                    $callback($conn);
                    fclose($conn);
                }
                // Socket is closed when kill signal from master process is sent
            }
        }
    }

    public function stop()
    {
        var_dump(posix_getpid() . ': killing ' . $this->proxyPid);
        posix_kill($this->proxyPid, SIGTERM);
        pcntl_wait($status);
    }
}


/**
 * StreamWrapper.
 */
class StreamWrapper
{
    private $config;

    public function __construct(Configuration $config)
    {
        $this->config = $config;
    }

    public function enable()
    {
        stream_context_set_default(
            array(
                'http' => array('proxy' => $this->config->getProxySocket()),
                'https' => array('proxy' => $this->config->getProxySocket()),
                'ftp' => array('proxy' => $this->config->getProxySocket()),
                'ftps' => array('proxy' => $this->config->getProxySocket()),
            )
        );
    }

    public function disable()
    {
        stream_context_set_default(array('http' => array()));
    }
}

$vcr = new VCR(new Configuration);
$vcr->insertCassette('bafoeg2go');
$vcr->turnOn();
// $vcr->record();

var_dump(file_get_contents('http://dev.bafoeg2go'));

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://dev.bafoeg2go");
curl_setopt($ch, CURLOPT_PROXY, 'tcp://127.0.0.1:8000');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_exec($ch));
curl_close($ch);
