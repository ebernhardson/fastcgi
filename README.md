# FastCGI Client

Enables you to request script execution from a FastCGI server. FastCGI is
a protocol commonly used for web servers to request script execution from a
FastCGI daemoni like php-fpm.

## Use Cases

* Executing any script that benefits from APC from the command line.
* Running code with an independent execution context from long running daemons
* More

## Installation
    
    $ composer require ebernhardson/fastcgi

## Usage

The client supports either tcp:

    $client = new \EBernhardson\FastCGI\Client('localhost', '8989');

Or unix sockets:

    $client = new \EBernhardson\FastCGI\Client('/var/run/php5-fpm.sock');


A FastCGI request is made using an array of environment parameters and a string
containing the request content.  When connecting to php-fpm the `$environment`
will be available in `$_SERVER`. The following environment is the minimum 
required by php-fpm to execute a script. 

    $environment = [
        'REQUEST_METHOD'  => 'GET',
        'SCRIPT_FILENAME' => '/full/path/to/script.php',
    ];
    $client->request($environment, '');

Once you have issued a request you must then fetch the response.  The `response`
method will block untill the request is complete.

    $response = $client->response();

The `response` method returns an array with four keys: `statusCode`, `headers`,
`body`, and `stderr`.

Currently you *MUST* call `response` before calling `request` again.  Failure
to do so will result in undefined behavior.  The FastCGI protocol does support
multiplexing so this may change in the future.

## Exceptions

Any communication failures with the FastCGI daemon will result in an exception.
The FastCGI `CommunicationException` extends from the SPL `RuntimeException`.

    try {
        $client->request($environment, $stdin);
        $response = $client->response();
    } catch (\EBernhardson\FastCGI\CommunicationException $failure) {
        // handle failure

    }

## Common environment variables passed over FastCGI


#### GATEWAY_INTERFACE

variable describing the version of the fastcgi protocol to use. Currently this 
client supports version 1.0.

    'GATEWAY_INTERFACE' => 'FastCGI/1.0'

#### REQUEST_METHOD

Required environment variable containing the method of the HTTP request.  
Possible values include `GET`, `HEAD`, `POST`, `PUT` and `DELETE`.

    'REQUEST_METHOD' => 'GET'

#### SCRIPT_FILENAME

Required environment variables containing the absolute path to the script being
executed.  This path must be accessible by the FastCGI server.

    'SCRIPT_FILENAME' => '/home/zomg/deploy/current/web/app.php'

#### QUERY_STRING

Optionally contains a query string.  You can use the `http_build_query` function to
format an array of key/value pairs in the expected format.

    'QUERY_STRING' => http_build_query(['key' => 'value'])

#### SERVER_SOFTWARE

The software used to make the FastCGI request.

    'SERVER_SOFTWARE' => 'awesome-application/1.0'

#### SERVER_NAME

The name of the server making this request

    'SERVER_NAME' => php_uname('n')

#### CONTENT_TYPE

When sending content along with the request, this specifies the mime type of the
content.

    'CONTENT_TYPE' => 'application/x-www-form-urlencoded'

#### CONTENT_LENGTH

When sending content along with the request, this specifies the length of the content.

    'CONTENT_LENGTH' => strlen($content)

## Examples

#### Passing HTTP headers

Http headers are sent prefixed with HTTP_.

    $client->request(
        [
            'REQUEST_METHOD'  => 'GET',
            'SCRIPT_FILENAME' => '/full/path/to/test.php',
            'HTTP_ACCEPT'     => 'application/json',
        ],
        ''
    );


#### POST Request

    $content = 'key=value';
    $client->request(
        [
            'REQUEST_METHOD'  => 'POST',
            'SCRIPT_FILENAME' => '/full/path/to/test.php',
            'CONTENT_TYPE'    => 'application/x-www-form-urlencoded',
            'CONTENT_LENGTH'  => strlen($content)
        ],
        $content
    );

#### Response from php-fpm on invalid SCRIPT_FILENAME

    array(4) {
      ["statusCode"]=>
      int(404)
      ["headers"]=>
      array(3) {
        ["status"]=>
        string(14) "404 Not Found"
        ["x-powered-by"]=>
        string(22) "PHP/5.4.9-4~precise+1"
        ["content-type"]=>
        string(9) "text/html"
      }
      ["body"]=>
      string(15) "File not found."
      ["stderr"]=>
      string(23) "Primary script unknown
    "
    }

#### Response from php-fpm on successfull execution

    array(4) {
      ["statusCode"]=>
      int(200)
      ["headers"]=>
      array(3) {
        ["x-powered-by"]=>
        string(22) "PHP/5.4.9-4~precise+1"
        ["content-type"]=>
        string(9) "text/html"
        ["status"]=>
        string(6) "200 OK"
      }
      ["body"]=>
      string(11) "Hello World"
      ["stderr"]=>
      string(0) ""
    }

