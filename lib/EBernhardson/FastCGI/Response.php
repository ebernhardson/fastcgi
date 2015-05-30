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

    /** @var int */
    public $state;
    /** @var string */
    public $stdout;
    /** @var string */
    public $stderr;

    private $reqID;
    private $resp;
    /** @var Client */
    private $conn;

    /**
     * @param Client $conn
     * @param int    $reqID
     */
    public function __construct(Client $conn, $reqID)
    {
        $this->reqID = $reqID;
        $this->conn = $conn;
    }

    /**
     * @param int $timeout
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

            $this->conn->wait_for_response($this->reqID, $timeout);
            $this->resp = self::formatResponse($this->stdout, $this->stderr);
        }
        return $this->resp;
    }

    /**
     * Format the response into an array with separate statusCode, headers, body, and error output.
     *
     * @param string $stdout The plain response.
     * @param string $stderr The plain error output.
     * @return array An array containing the headers and body content.
     */
    private static function formatResponse($stdout, $stderr)
    {
        // Split the header from the body.  Split on \n\n.
        $doubleCr = strpos($stdout, "\r\n\r\n");
        $rawHeader = substr($stdout, 0, $doubleCr);
        $rawBody = substr($stdout, $doubleCr, strlen($stdout));

        // Format the header.
        $header = array();
        $headerLines = explode("\n", $rawHeader);

        // Initialize the status code and the status header
        $code = '200';
        $headerStatus = '200 OK';

        // Iterate over the headers found in the response.
        foreach ($headerLines as $line) {

            // Extract the header data.
            if (preg_match('/([\w-]+):\s*(.*)$/', $line, $matches)) {

                // Initialize header name/value.
                $headerName = strtolower($matches[1]);
                $headerValue = trim($matches[2]);

                // If we found an status header (will only be available if not have a 200).
                if ($headerName == 'status') {

                    // Initialize the status header and the code.
                    $headerStatus = $headerValue;
                    $code = $headerValue;
                    if (false !== ($pos = strpos($code, ' '))) {
                        $code = substr($code, 0, $pos);
                    }
                }

                // We need to know if this header is already available
                if (array_key_exists($headerName, $header)) {

                    // Check if the value is an array already
                    if (is_array($header[$headerName])) {
                        // Simply append the next header value
                        $header[$headerName][] = $headerValue;
                    } else {
                        // Convert the existing value into an array and append the new header value
                        $header[$headerName] = array($header[$headerName], $headerValue);
                    }

                } else {
                    $header[$headerName] = $headerValue;
                }
            }
        }

        // Set the status header finally
        $header['status'] = $headerStatus;

        return array(
            'statusCode' => (int) $code,
            'headers'    => $header,
            'body'       => trim($rawBody),
            'stderr'     => $stderr,
        );
    }
}
