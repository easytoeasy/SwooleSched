<?php

namespace pzr\swoolesched;

define('PHP_EOL', "\r\n");

class Http
{
    public static $basePath = __DIR__ . '/views/';
    public $max_age = 120; //秒


    /*
    *  函数:     parse_http
    *  描述:     解析http协议
    */
    public static function parseHttp($header)
    {
        if (empty($header)) return false;
        $_SERVER = $_GET = $_POST = $_REQUEST = array();
        $headerMaps = [
            'host' => 'HTTP_HOST',
            'cookie' => 'HTTP_COOKIE',
            'connection' => 'HTTP_CONNECTION',
            'user-agent' => 'HTTP_USER_AGENT',
            'accept' => 'HTTP_ACCEPT',
            'referer' => 'HTTP_REFERER',
            'accept-encoding' => 'HTTP_ACCEPT_ENCODING',
            'accept-language' => 'HTTP_ACCEPT_LANGUAGE',
            'if-modified-since' => 'HTTP_IF_MODIFIED_SINCE',
            'content-type' => 'CONTENT_TYPE',
            'if-none-match' => 'HTTP_IF_NONE_MATCH',
        ];

        list($httpHeader, $httpBody) = explode("\r\n\r\n", $header, 2);
        $headers = explode("\r\n", $httpHeader);
        // print_r($headers);
        list(
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['REQUEST_URI'],
            $_SERVER['SERVER_PROTOCOL']
        ) = explode(' ', $headers[0]);

        $suffix = '';
        if (strpos($_SERVER['REQUEST_URI'], '.') !== false) {
            list($prefix, $suffix) = explode('.', $_SERVER['REQUEST_URI']);
        }
        switch ($suffix) {
            case 'css':
                $_SERVER['CONTENT_TYPE'] = 'text/css';
                break;
            case 'gif':
                $_SERVER['CONTENT_TYPE'] = 'image/gif';
                break;
            case 'png':
            case 'ico':
                $_SERVER['CONTENT_TYPE'] = 'image/png';
                break;
            default:
                $_SERVER['CONTENT_TYPE'] = 'text/html';
                break;
        }


        unset($headers[0]);
        foreach ($headers as $str) {
            if (empty($str)) continue;
            list($key, $value) = explode(':', $str, 2);
            $key = strtolower($key);
            $value = trim($value);
            if (!array_key_exists($key, $headerMaps)) {
                continue;
            }
            $_SERVER[$headerMaps[$key]] = $value;
            switch ($key) {
                case 'host':
                    $tmp = explode(':', $value);
                    $_SERVER['SERVER_NAME'] = $tmp[0];
                    if (isset($tmp[1])) {
                        $_SERVER['SERVER_PORT'] = $tmp[1];
                    }
                    break;
                case 'cookie':
                    parse_str(str_replace('; ', '&', $_SERVER['HTTP_COOKIE']), $_COOKIE);
                    break;
                case 'content-type':
                    if (!preg_match('/boundary="?(\S+)"?/', $value, $match)) {
                        $_SERVER['CONTENT_TYPE'] = $value;
                    } else {
                        $_SERVER['CONTENT_TYPE'] = 'multipart/form-data';
                        $http_post_boundary = '--' . $match[1];
                    }
                    break;
            }

            // script_name
            $_SERVER['SCRIPT_NAME'] = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

            // QUERY_STRING
            $_SERVER['QUERY_STRING'] = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
            if ($_SERVER['QUERY_STRING']) {
                // $GET
                parse_str($_SERVER['QUERY_STRING'], $_GET);
            } else {
                $_SERVER['QUERY_STRING'] = '';
            }
            // REQUEST
            $_REQUEST = array_merge($_GET, $_POST);
        }
    }

    public static function status_404()
    {
        $keepalive = '';
        if ($_SERVER['HTTP_CONNECTION'] == 'keep-alive') {
            $keepalive = 'Connection: Keep-Alive';
        }
        return <<<EOF
HTTP/1.1 404 OK
content-type: text/html
$keepalive

EOF;
    }

    public static function status_301($location)
    {
        $keepalive = '';
        if ($_SERVER['HTTP_CONNECTION'] == 'keep-alive') {
            $keepalive = 'Connection: Keep-Alive';
        }
        return <<<EOF
HTTP/1.1 301 Moved Permanently
Content-Length: 0
Content-Type: text/plain
Location: $location
Cache-Control: no-cache
$keepalive

EOF;
    }

    public static function status_304()
    {
        $keepalive = '';
        if ($_SERVER['HTTP_CONNECTION'] == 'keep-alive') {
            $keepalive = 'Connection: Keep-Alive';
        }
        return <<<EOF
HTTP/1.1 304 Not Modified
Content-Length: 0
$keepalive

EOF;
    }

    public static function status_200($response)
    {
        $header = '';
        if ($_SERVER['HTTP_CONNECTION'] == 'keep-alive') {
            $header = 'Connection: Keep-Alive' . PHP_EOL;
        }
        $contentType = $_SERVER['CONTENT_TYPE'];
        $length = strlen($response);
        $header .= $contentType ? 'Cache-Control: max-age=180' : '';
        // $etag = md5($response);
        // ETag: $etag
        return <<<EOF
HTTP/1.1 200 OK
Content-Type: $contentType
Content-Length: $length
$header

$response
EOF;
    }
}
