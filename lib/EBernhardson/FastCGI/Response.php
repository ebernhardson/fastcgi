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
 * A reference to stdout/stderr for a response.
 *
 * @author Jesse Decker <me@jessedecker.com>
 * @since  2.1
 */
class Response
{
    const REQ_STATE_WRITTEN    = 1;
    const REQ_STATE_OK         = 2;
    const REQ_STATE_ERR        = 3;
    const REQ_STATE_TIMED_OUT  = 4;

    /** @var Integer */
    public $state;
    /** @var String */
    public $stdout;
    /** @var String */
    public $stderr;

    /** @var Integer */
    private $reqID;
    /** @var array */
    private $resp;
    /** @var Client */
    private $conn;

    /**
     * @param Client  $conn
     * @param Integer $reqID
     */
    public function __construct(Client $conn, $reqID)
    {
        $this->reqID = $reqID;
        $this->conn = $conn;
    }

    /**
     * Retrieve the Request ID used to create this instance.
     *
     * @return Integer
     */
    public function getId()
    {
        return $this->reqID;
    }

    /**
     * @param Integer $timeout
     * @return array
     */
    public function get($timeout = 0)
    {
        if ($this->resp === null) {

            // If we already read the response during an earlier call for different id, just return it
            if ($this->state == self::REQ_STATE_OK
                || $this->state == self::REQ_STATE_ERR
            ) {
                return $this->resp;
            }

            $this->conn->waitForResponse($this->reqID, $timeout);
            $this->resp = self::formatResponse($this->stdout, $this->stderr);
        }
        return $this->resp;
    }

    /**
     * Format the response into an array with separate statusCode, headers, body, and error output.
     *
     * @param String $stdout The plain response.
     * @param String $stderr The plain error output.
     * @return array An array containing the headers and body content.
     */
    private static function formatResponse($stdout, $stderr)
    {
        $code     = 200;
        $headers  = [
            // An empty status means 200 OK, so initialize with defaults
            'status' => '200 OK',
        ];

        // HTTP uses 2 CR/NL's to separate body from header
        $boundary = strpos($stdout, "\r\n\r\n");
        if (false !== $boundary) {

            // Split the header from the body
            $rawHead = substr($stdout, 0, $boundary);
            $stdout = substr($stdout, $boundary + 4);

            // Iterate over the found headers
            $headerLines = explode("\n", $rawHead);
            foreach ($headerLines as $line) {

                // Extract the header data
                if (preg_match('/([\w-]+):\s*(.*)$/', $line, $matches)) {

                    // Normalize header name/value
                    $headerName  = strtolower($matches[1]);
                    $headerValue = trim($matches[2]);

                    // HTTP Status (will frequently only be found non-200)
                    if ($headerName === 'status') {
                        $headers['status'] = $headerValue;

                        // Extract the number from the rest of the message
                        $pos  = strpos($headerValue, ' ') ;
                        $code = $pos > 0
                            ? (int) substr($headerValue, 0, $pos)
                            : (int) $headerValue;

                        // Skip re-setting
                        continue;
                    }

                    if (array_key_exists($headerName, $headers)) {
                        // Ensure is array
                        if (!is_array($headers[$headerName])) {
                            $headers[$headerName] = [ $headers[$headerName] ];
                        }
                        $headers[$headerName][] = $headerValue;
                    } else {
                        $headers[$headerName] = $headerValue;
                    }
                }
            }
        }

        return array(
            'statusCode' => $code,
            'headers'    => $headers,
            'body'       => $stdout,
            'stderr'     => $stderr,
        );
    }
}
