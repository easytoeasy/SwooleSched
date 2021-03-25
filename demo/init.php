<?php

const HOST = '127.0.0.1';
const PORT = 10932;
const BACKLOG = 100;

const CRLF = "\r\n";
const MAXLEN = 1 * 1024 * 5; //5KB

function write($fd, $data)
{
    while (!empty($data)) {
        $written = socket_write($fd, $data, strlen($data));
        if ($written === false) {
            $errno = socket_last_error($fd);
            if ($errno == 4 || $errno == 35) continue;
            getErrmsg($fd);
            return false;
        }
        $data = substr($data, $written);
    }
    return strlen($data);
}

/** 客户端在阻塞模式下等待服务端的响应
 * 客户端在阻塞模式下读取服务端的响应是不会有问题的，
 * 但是如果在非阻塞模式下读取，如果没有while(empty($reply))
 * 就会出现问题，数据会发现串起来的现象。 */
function read($fd)
{
    $reply = $buf = '';
    while (empty($reply)) {
        if (($buf = socket_read($fd, MAXLEN)) === false) {
            $errno = socket_last_error($fd);
            if ($errno == 4 || $errno == 35) continue;
            getErrmsg($fd);
            unset($buf, $reply);
            free($fd);
            return false;
        }
        // 连接已关闭，这就是read函数有问题的地方。
        if ($buf === '' || $buf === -1) {
            echo 'buf:' . $buf . PHP_EOL;
            return false;
        }
        $reply .= $buf;
    }
    return $reply;
}

/** 服务端读取客户端的命令 */
function readNormal($fd)
{
    $reply = $buf = '';
    while (substr($buf, -1, 1) !== "\n") {
        if (($buf = socket_read($fd, 1024, PHP_NORMAL_READ)) === false) {
            $errno = socket_last_error($fd);
            if ($errno == 4 || $errno == 35) continue;
            getErrmsg($fd);
            unset($buf, $reply);
            free($fd);
            return false;
        }
        // 连接已关闭，这就是read函数有问题的地方。
        if ($buf === '' || $buf === -1) {
            echo 'buf:' . $buf . PHP_EOL;
            return false;
        }
        $reply .= $buf;
    }
    return $reply;
}

function getErrmsg($fd = null)
{
    $errno = socket_last_error($fd);
    $msg = socket_strerror($errno);
    printf("errno:%s, errmsg:%s \n", $errno, $msg);
}

function freeSockets(array $clients)
{
    return array_walk($clients, function ($c) {
        printf("关闭连接：%s \n", $c);
        socket_close($c);
    });
}

function release($cfd, &$clients)
{
    if ($clients && ($index = array_search($cfd, $clients)) !== false) {
        unset($clients[$index]);
        printf("关闭连接：%s \n", $cfd);
        is_resource($cfd) && socket_close($cfd);
    }
}

function free($fd)
{
    printf("关闭连接：%s \n", $fd);
    is_resource($fd) && socket_close($fd);
}








function status_200($response)
{
    $contentType = $_SERVER['CONTENT_TYPE'];
    $length = strlen($response);
    $header = '';
    if ($contentType)
        $header = 'Cache-Control: max-age=180';
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

function parseHttp($header)
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
