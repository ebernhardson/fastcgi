<?php
/**
 * Note : Code is released under the GNU LGPL
 *
 * Please do not change the header of this file
 *
 * This library is free software; you can redistribute it and/or modify it under the terms of the GNU
 * Lesser General Public License as published by the Free Software Foundation; either version 2 of
 * the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * See the GNU Lesser General Public License for more details.
 */

namespace EBernhardson\FastCGI;

/**
 * Handles communication with a FastCGI application
 *
 * @author      Pierrick Charron <pierrick@webstart.fr>
 * @author      Daniel Aharon <dan@danielaharon.com>
 * @author      Erik Bernhardson <bernhardsonerik@gmail.com>
 * @author      Jesse Decker <me@jessedecker.com>
 * @version     2.1
 */
class Client
{
    const VERSION_1            = 1;

    // Packet types
    const BEGIN_REQUEST        = 1;
    const ABORT_REQUEST        = 2;
    const END_REQUEST          = 3;
    const PARAMS               = 4;
    const STDIN                = 5;
    const STDOUT               = 6;
    const STDERR               = 7;
    const DATA                 = 8;
    const GET_VALUES           = 9;
    const GET_VALUES_RESULT    = 10;
    const UNKNOWN_TYPE         = 11;
    //const MAXTYPE              = self::UNKNOWN_TYPE;

    const RESPONDER            = 1;
    const AUTHORIZER           = 2;
    const FILTER               = 3;

    // Response codes
    const REQUEST_COMPLETE     = 0;
    const CANT_MPX_CONN        = 1;
    const OVERLOADED           = 2;
    const UNKNOWN_ROLE         = 3;

    //const MAX_CONNS            = 'MAX_CONNS';
    //const MAX_REQS             = 'MAX_REQS';
    //const MPXS_CONNS           = 'MPXS_CONNS';

    // Number of bytes used in FastCGI header packet
    const HEADER_LEN           = 8;

    /**
     * Socket
     * @var Resource
     */
    protected $sock;

    /**
     * Host
     * @var String
     */
    protected $host;

    /**
     * Port
     * @var Integer
     */
    protected $port;

    /**
     * Keep Alive
     * @var Boolean
     */
    protected $keepAlive = false;

    /**
     * Outstanding request statuses keyed by request id
     *
     * Each request is an array with following form:
     *
     *  array(
     *    'state' => REQ_STATE_*
     *    'stdout' => null | string
     *    'stdin' => null | string
     *  )
     *
     * @var Response[]
     */
    protected $_requests = array();

    /**
     * Unsigned 16-bit integer incremented with each request to generate a unique ID.
     * @var Integer
     */
    protected $_requestCounter = 0;

    /**
     * Read/Write timeout in milliseconds
     * @var Integer
     */
    protected $_readWriteTimeout = 0;

    /**
     * Constructor
     *
     * @param String $host Host of the FastCGI application or path to the FastCGI unix socket
     * @param Integer $port Port of the FastCGI application or null for the FastCGI unix socket
     */
    public function __construct($host, $port = null)
    {
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * @return array
     */
    public function __sleep()
    {
        return array('host','port','_readWriteTimeout');
    }

    /**
     * Define whether or not the FastCGI application should keep the connection
     * alive at the end of a request
     *
     * @param Boolean $b true if the connection should stay alive, false otherwise
     */
    public function setKeepAlive($b)
    {
        $this->keepAlive = (boolean)$b;
        if (!$this->keepAlive && $this->sock) {
            $this->close();
        }
    }

    /**
     * Get the keep alive status
     *
     * @return Boolean true if the connection should stay alive, false otherwise
     */
    public function getKeepAlive()
    {
        return $this->keepAlive;
    }

    /**
     * Close the FastCGI connection
     */
    public function close()
    {
        if ($this->sock) {
            socket_close($this->sock);
            $this->sock = null;
        }
        $this->_requests = [];
    }

    /**
     * Set the read/write timeout
     *
     * @param Integer  number of milliseconds before read or write call will timeout
     */
    public function setReadWriteTimeout($timeoutMs)
    {
        $this->_readWriteTimeout = $timeoutMs;
        $this->setMsTimeout($this->_readWriteTimeout);
    }

    /**
     * Get the read timeout
     *
     * @return Integer  number of milliseconds before read will timeout
     */
    public function getReadWriteTimeout()
    {
        return $this->_readWriteTimeout;
    }

    /**
     * Helper to avoid duplicating milliseconds to secs/usecs in a few places
     *
     * @param Integer $timeoutMs millisecond timeout
     * @return Boolean
     */
    private function setMsTimeout($timeoutMs) {
        if (!$this->sock) {
            return false;
        }
        $timeout = array(
            'sec' => floor($timeoutMs / 1000),
            'usec' => ($timeoutMs % 1000) * 1000,
        );
        return socket_set_option($this->sock, SOL_SOCKET, SO_RCVTIMEO, $timeout);
    }


    /**
     * Create a connection to the FastCGI application
     */
    protected function connect()
    {
        if ($this->sock) {
            return;
        }
        if ($this->port) {
            $this->sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            $address = $this->host;
            $port = $this->port;
        } else {
            $this->sock = socket_create(AF_UNIX, SOCK_STREAM, 0);
            $address = $this->host;
            $port = 0;
        }
        if (!$this->sock) {
            throw CommunicationException::socketCreate();
        }
        if (false === socket_connect($this->sock, $address, $port)) {
            throw CommunicationException::socketConnect($this->sock, $this->host, $this->port);
        }
        if ($this->_readWriteTimeout && !$this->setMsTimeout($this->_readWriteTimeout)) {
            throw new CommunicationException('Unable to set timeout on socket');
        }
    }

    /**
     * Build a FastCGI packet
     *
     * @param Integer $type Type of the packet
     * @param String $content Content of the packet
     * @param Integer $requestId RequestId
     * @return String
     */
    protected function buildPacket($type, $content, $requestId = 1)
    {
        $offset = 0;
        $totLen = strlen($content);
        $buf    = '';
        do {
            // Packets can be a maximum of 65535 bytes
            $part = substr($content, $offset, 0xffff - 8);
            $segLen = strlen($part);
            $buf .= chr(self::VERSION_1)        /* version */
                . chr($type)                    /* type */
                . chr(($requestId >> 8) & 0xFF) /* requestIdB1 */
                . chr($requestId & 0xFF)        /* requestIdB0 */
                . chr(($segLen >> 8) & 0xFF)    /* contentLengthB1 */
                . chr($segLen & 0xFF)           /* contentLengthB0 */
                . chr(0)                        /* paddingLength */
                . chr(0)                        /* reserved */
                . $part;                        /* content */
            $offset += $segLen;
        } while ($offset < $totLen);
        return $buf;
    }

    /**
     * Build an FastCGI Name value pair
     *
     * @param String $name Name
     * @param String $value Value
     * @return String FastCGI Name value pair
     */
    protected function buildNvpair($name, $value)
    {
        $nlen = strlen($name);
        $vlen = strlen($value);
        if ($nlen < 128) {
            /* nameLengthB0 */
            $nvpair = chr($nlen);
        } else {
            /* nameLengthB3 & nameLengthB2 & nameLengthB1 & nameLengthB0 */
            $nvpair = chr(($nlen >> 24) | 0x80) . chr(($nlen >> 16) & 0xFF) . chr(($nlen >> 8) & 0xFF) . chr($nlen & 0xFF);
        }
        if ($vlen < 128) {
            /* valueLengthB0 */
            $nvpair .= chr($vlen);
        } else {
            /* valueLengthB3 & valueLengthB2 & valueLengthB1 & valueLengthB0 */
            $nvpair .= chr(($vlen >> 24) | 0x80) . chr(($vlen >> 16) & 0xFF) . chr(($vlen >> 8) & 0xFF) . chr($vlen & 0xFF);
        }
        /* nameData & valueData */
        return $nvpair . $name . $value;
    }

    /**
     * Read a set of FastCGI Name value pairs
     *
     * @param String $data Data containing the set of FastCGI NVPair
     * @param Integer $length
     * @return array of NVPair
     */
    protected function readNvpair($data, $length = null)
    {
        if ($length === null) {
            $length = strlen($data);
        }

        $array = array();
        $p = 0;
        while ($p != $length) {
            $nlen = ord($data{$p++});
            if ($nlen >= 128) {
                $nlen = ($nlen & 0x7F << 24);
                $nlen |= (ord($data{$p++}) << 16);
                $nlen |= (ord($data{$p++}) << 8);
                $nlen |= (ord($data{$p++}));
            }
            $vlen = ord($data{$p++});
            if ($vlen >= 128) {
                $vlen = ($nlen & 0x7F << 24);
                $vlen |= (ord($data{$p++}) << 16);
                $vlen |= (ord($data{$p++}) << 8);
                $vlen |= (ord($data{$p++}));
            }
            $array[substr($data, $p, $nlen)] = substr($data, $p+$nlen, $vlen);
            $p += ($nlen + $vlen);
        }

        return $array;
    }

    /**
     * Decode a FastCGI Packet
     *
     * @param String $data String containing all the packet
     * @return array
     */
    protected function decodePacketHeader($data)
    {
        $ret = array();
        $ret['version']       = ord($data{0});
        $ret['type']          = ord($data{1});
        $ret['requestId']     = (ord($data{2}) << 8) + ord($data{3});
        $ret['contentLength'] = (ord($data{4}) << 8) + ord($data{5});
        $ret['paddingLength'] = ord($data{6});
        $ret['reserved']      = ord($data{7});
        return $ret;
    }

    /**
     * Read a FastCGI Packet
     *
     * @param Integer $timeoutMs
     * @return array|null
     * @throws CommunicationException
     * @throws TimedOutException
     */
    protected function readPacket($timeoutMs)
    {
        $s = [$this->sock];
        $a = [];
        socket_select($s, $a, $a, floor($timeoutMs / 1000), ($timeoutMs % 1000) * 1000);

        $packet = socket_read($this->sock, self::HEADER_LEN);
        if ($packet === false) {
            $errNo = socket_last_error($this->sock);
            if ($errNo == 110) { // ETIMEDOUT from http://php.net/manual/en/function.socket-last-error.php
                throw new TimedOutException('Failed reading socket');
            }
            //TODO: Determine way to check if FPM is blocking the client
            /* Not relevant for socket_create() but very interesting...
            $info = stream_get_meta_data($this->_sock);
            if ($info['unread_bytes'] == 0 && $info['blocked'] && $info['eof']) {
                throw new CommunicationException('Not in white list. Check listen.allowed_clients.');
            }*/
            throw CommunicationException::socketRead($this->sock);
        }
        if (!$packet) {
            return null;
        }

        $resp = $this->decodePacketHeader($packet);
        $resp['content'] = '';
        if ($resp['contentLength']) {
            $len  = $resp['contentLength'];
            while ($len && $buf=socket_read($this->sock, $len)) {
                $len -= strlen($buf);
                $resp['content'] .= $buf;
            }
        }
        if ($resp['paddingLength']) {
            /*$buf = */socket_read($this->sock, $resp['paddingLength']);
            // throw-away padding...
        }
        return $resp;
    }

    /**
     * Get information on the FastCGI application
     *
     * @param array $requestedInfo information to retrieve
     * @return array
     * @throws CommunicationException
     */
    public function getValues(array $requestedInfo)
    {
        $this->connect();

        $request = '';
        foreach ($requestedInfo as $info) {
            $request .= $this->buildNvpair($info, '');
        }
        $ret = socket_write($this->sock, $this->buildPacket(self::GET_VALUES, $request, 0));
        if ($ret === false) {
            throw CommunicationException::socketWrite($this->sock);
        }

        $resp = $this->readPacket(0);
        if ($resp['type'] == self::GET_VALUES_RESULT) {
            return $this->readNvpair($resp['content'], $resp['length']);
        } else {
            throw new CommunicationException('Unexpected response type, expecting GET_VALUES_RESULT');
        }
    }

    /**
     * Execute a request to the FastCGI application
     *
     * @param array $params Array of parameters
     * @param String $stdin Content
     * @return String
     */
    public function request(array $params, $stdin)
    {
        $req = $this->asyncRequest($params, $stdin);
        return $req->get();
    }

    /**
     * Execute a request to the FastCGI application asynchronously
     * This sends request to application and returns the assigned ID for that request.
     * You should keep this id for later use with wait_for_response(). Ids are chosen randomly
     * rather than sequentially to guard against false-positives when using persistent sockets.
     * In that case it is possible that a delayed response to a request made by a previous script
     * invocation comes back on this socket and is mistaken for response to request made with same ID
     * during this request.
     *
     * @param array  $params Array of parameters
     * @param String $stdin  Content
     * @return Response
     * @throws CommunicationException
     */
    public function asyncRequest(array $params, $stdin)
    {
        $this->connect();

        // Ensure new requestID is not already being tracked
        do {
            $this->_requestCounter++;
            if ($this->_requestCounter >= 65536 /* or (1 << 16) */) {
                $this->_requestCounter = 1;
            }
            $id = $this->_requestCounter;
        } while (isset($this->_requests[$id]));

        $request = $this->buildPacket(self::BEGIN_REQUEST, chr(0) . chr(self::RESPONDER) . chr((int) $this->keepAlive) . str_repeat(chr(0), 5), $id);

        $paramsRequest = '';
        foreach ($params as $key => $value) {
            $paramsRequest .= $this->buildNvpair($key, $value, $id);
        }
        if ($paramsRequest) {
            $request .= $this->buildPacket(self::PARAMS, $paramsRequest, $id);
        }
        $request .= $this->buildPacket(self::PARAMS, '', $id);

        if ($stdin) {
            $request .= $this->buildPacket(self::STDIN, $stdin, $id);
        }
        $request .= $this->buildPacket(self::STDIN, '', $id);

        if (false === socket_write($this->sock, $request)) {
            // The developer may wish to close() and re-open the socket
            throw CommunicationException::socketWrite($this->sock);
        }

        $req = new Response($this, $id);
        $req->state = Response::REQ_STATE_WRITTEN;
        $this->_requests[$id] = $req;

        return $req;
    }

    /**
     * Blocking call that waits for response to specific request
     *
     * @param Integer $requestId
     * @param Integer $timeoutMs [optional] the number of milliseconds to wait
     * @return bool
     * @throws CommunicationException
     * @throws TimedOutException
     */
    public function waitForResponse($requestId, $timeoutMs = 0)
    {
        if (!isset($this->_requests[$requestId])) {
            throw new CommunicationException('Invalid request id given');
        }

        // Need to manually check since we might do several reads none of which timeout themselves
        // but still not get the response requested
        $startTime = microtime(true);

        do {
            $resp = $this->readPacket($timeoutMs);
            if (!$resp) {
                continue; // block again
            }

            if (isset($this->_requests[$resp['requestId']])) {
                $req = $this->_requests[$resp['requestId']];
                $respType = (int) $resp['type'];

                if ($respType === self::STDOUT) {
                    $req->stdout .= $resp['content'];
                } elseif ($respType === self::STDERR) {
                    $req->state = Response::REQ_STATE_ERR;
                    $req->stderr .= $resp['content'];
                } elseif ($respType === self::END_REQUEST) {
                    $req->state = Response::REQ_STATE_OK;
                    // Don't need to track this request anymore
                    unset($this->_requests[$resp['requestId']]);
                    if ($resp['requestId'] == $requestId) {
                        return true;
                    }
                }
            } else {
                // This is NOT especially an error condition.
                // Maybe there was a previous instance of this class that controlled the socket,
                // or maybe the developer has extended things weirdly.
                // We can't use the data, so we should log something.
                trigger_error("Bad requestID: " . $resp['requestId'], E_USER_WARNING);
            }

            // Process special message
            if (isset($resp['content']{4})) {
                $msg = ord($resp['content']{4});
                if ($msg === self::CANT_MPX_CONN) {
                    throw new CommunicationException('This app can\'t multiplex [CANT_MPX_CONN]');
                } elseif ($msg === self::OVERLOADED) {
                    throw new CommunicationException('New request rejected; too busy [OVERLOADED]');
                } elseif ($msg === self::UNKNOWN_ROLE) {
                    throw new CommunicationException('Role value not known [UNKNOWN_ROLE]');
                }
            }

            if ($timeoutMs && microtime(true) - $startTime >= ($timeoutMs * 1000)) {
                throw new TimedOutException('Timed out');
            }

        } while (true);

        return false;
    }
}
