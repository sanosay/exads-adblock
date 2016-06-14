<?php
/**
 * Ad loader script that allows loading external ads
 * without making detectable browser requests to external javascripts or other resources.
 */

define('SCRIPT_VERSION', 'php_2.0');
define('CONNECT_TIMEOUT', 1);
define('CACHE_PREFIX', 'exo_v1_');
define('CACHE_INTERVAL_BANNERS', 3600); // id set to 0, banners won't be cached
define('CACHE_KEYS_LIMIT_BANNERS', 100); //if set to 0, there will be no limit for the amount of keys this script can set
define('CACHE_INTERVAL_SCRIPTS', 3600);
define('POPUNDER_RESOURCE_URL', "https://syndication.exoclick.com/splash.php?type=3");
define('MULTI_BANNER_RESOURCE_URL', "https://syndication.exoclick.com/ads-multi.php?block=1");
define('ADS_COOKIE_NAME', 'exo_zones');
define('ALLOW_MULTI_CURL', true);
define('VERIFY_PEER', true);

interface ResponseInterface
{
    public function getBody();
    public function getHeaders();
    public function getHeader($name);
    public function getHttpCode();
    public function getCookies();
    public function setBody($body);
    public function setHeaders(array $rawHeaders);
    public function appendHeader($rawHeader);
    public function appendCookies(array $cookies);
}

interface RequestGetterInterface
{
    public function resolve($url, $verify_peer = true);
    public function resolveMulti(array $urls, $verify_peer = true);
}

interface ErrorLoggerInterface
{
    public function log($error);
    public function getErrors();
}

interface CacheInterface
{
    public function get($key);
    public function set($key, $value, $ttl);
    public function delete($key);
    public function increment($key, $step);
    public function decrement($key, $step);
}

class SimpleHttpResponse implements ResponseInterface
{
    private $httpCode;
    private $body;
    private $rawHeaders = array();
    private $headers = array();
    private $cookies = array();

    public function setBody($body) {
        $this->body = $body;
    }

    public function getBody() {
        return $this->body;
    }

    public function getHttpCode() {
        return $this->httpCode;
    }

    public function setHeaders(array $rawHeaders) {
        $this->rawHeaders = $rawHeaders;
        $parsedHeaders = $this->parseHeaders($rawHeaders);
        $this->httpCode = $parsedHeaders['http_code'];
        $this->headers = $parsedHeaders['headers'];
        $this->appendCookies($parsedHeaders['cookies']);
    }

    public function appendHeader($rawHeader) {
        $this->rawHeaders[] = $rawHeader;
        $parsedHeaders = $this->parseHeaders(array($rawHeader));
        if (!empty($parsedHeaders['http_code'])) {
            $this->httpCode = $parsedHeaders['http_code'];
        }
        $this->headers = array_merge($this->headers, $parsedHeaders['headers']);
        $this->appendCookies($parsedHeaders['cookies']);
    }

    public function getHeaders() {
        return $this->headers;
    }

    public function getHeader($name) {
        if (isset($this->headers[$name])) {
            return $this->headers[$name];
        }
        return null;
    }

    public function appendCookies(array $cookies) {
        $this->cookies = array_merge($this->cookies, $cookies);
    }

    public function getCookies() {
        return $this->cookies;
    }

    /**
     * @param array $headerLines
     * @return array
     */
    public function parseHeaders($headerLines)
    {
        $head = array(
            'headers' => array(),
            'cookies' => array(),
            'http_code' => ''
        );
        foreach($headerLines as $k=>$v)
        {
            $t = explode( ':', $v, 2 );
            if( isset( $t[1] ) )
                if (preg_match('/^Set-Cookie:\s*([^;]*)/mi', $v, $cookie) == 1) {
                    if (!empty($cookie[1])) {
                        $key_val = explode('=', $cookie[1]);
                        $head['cookies'][$key_val[0]] = urldecode($key_val[1]);
                    }
                } else {
                    $head['headers'][trim($t[0])] = trim($t[1]);
                }
            else
            {
                $head[] = $v;
                if( preg_match( "#HTTP/[0-9\.]+\s+([0-9]+)#",$v, $out ) )
                    $head['http_code'] = intval($out[1]);
            }
        }
        return $head;
    }
}

class FsockGetter implements RequestGetterInterface
{
    /**
     * @var ErrorLoggerInterface
     */
    private $errorLogger;

    public function __construct(ErrorLoggerInterface &$errorLogger) {
        $this->errorLogger = $errorLogger;
    }

    /**
     * @param $responseBody
     * @return bool|number
     */
    protected function parseNextChunkSize($responseBody)
    {
        $chunkSize = mb_strstr($responseBody, "\r\n", true);
        if ($chunkSize === false
            || $chunkSize === ""
            || !preg_match("/^([a-zA-Z0-9]*)(;.*)?$/", $chunkSize, $matches)
        ) {
            //something is wrong, can't get valid chunk size
            $this->errorLogger->log("can't get chunk size");
            return false;
        }
        return hexdec($matches[1]);
    }

    /**
     * @param $responseBody
     * @return bool|string
     */
    protected function parseChunkedContent($responseBody)
    {
        $content = "";
        while ($size = $this->parseNextChunkSize($responseBody)) {
            $responseBody = mb_strstr($responseBody, "\r\n");
            $chunk = substr($responseBody, 2, $size);
            $content .= $chunk;
            if (substr($responseBody, $size + 2, 2) !== "\r\n") {
                var_dump(substr($responseBody, $size + 2, 2));
                //there should be a new line after the chunk
                $this->errorLogger->log("malformated chunk");
                return false;
            }
            $responseBody = substr($responseBody, $size + 4);
        }
        if ($size === false) {
            return false;
        }
        return $content;
    }

    public function resolveMulti(array $urls, $verify_peer = true)
    {
        $responses = array();
        foreach ($urls as $url) {
            $responses[] = $this->resolve($url, $verify_peer);
        }
        return $responses;
    }

    /**
     * @param $url
     * @param bool $verify_peer
     * @return bool|ResponseInterface
     * @internal param array $prev_cookies
     */
    public function resolve($url, $verify_peer = true)
    {
        $response = new SimpleHttpResponse();
        $rawResponse = "";
        $urlParts = parse_url($url);

        if (empty($urlParts["host"])
            || !isset($urlParts["scheme"])
            || !in_array($urlParts["scheme"], array("http", "https"))
        ) {
            $this->errorLogger->log("invalid url");
            return false;
        }

        $sslPrefix = ($urlParts["scheme"] == "https") ? "ssl://" : "";
        $port = ($urlParts["scheme"] == "https") ? 443 : 80;
        $path = isset($urlParts["path"]) ? $urlParts["path"] : "/";
        $query = isset($urlParts["query"]) ? "?" . $urlParts["query"] : "";


        if (!$verify_peer) {
            $context = stream_context_create();
            stream_context_set_option($context, "ssl", "allow_self_signed", true);
            stream_context_set_option($context, "ssl", "verify_peer", false);
            $fp = stream_socket_client($sslPrefix . $urlParts["host"] . ":" . $port, $errno, $errstr, CONNECT_TIMEOUT, STREAM_CLIENT_CONNECT, $context);
        } else {
            $fp = fsockopen($sslPrefix . $urlParts["host"], $port, $errno, $errstr, CONNECT_TIMEOUT);
        }

        if (!$fp) {
            $this->errorLogger->log("fsockopen failed: " . $errno . " - " . $errstr);
            return false;
        } else {
            $out = "GET " . $path . $query . " HTTP/1.1\r\n";
            $out .= "Host: " . $urlParts["host"] . "\r\n";
            $out .= "User-Agent: " . @$_SERVER['HTTP_USER_AGENT'] . "\r\n";
            $out .= "Referer: " . @$_SERVER['HTTP_REFERER'] . "\r\n";
            $out .= "X-Forwarded-For: " . @((!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR']) . "\r\n";
            $out .= "Accept-Language: " . @$_SERVER['HTTP_ACCEPT_LANGUAGE'] . "\r\n";
            $out .= "Exo-Script-Version: " . SCRIPT_VERSION . "\r\n";
            $out .= "Connection: Close\r\n\r\n";
            fwrite($fp, $out);
            while (!feof($fp)) {
                $rawResponse .= fgets($fp, 128);
            }
            fclose($fp);
        }

        $parts = explode("\r\n\r\n", $rawResponse, 2);
        $headerLines = !empty($parts[0]) ? explode("\r\n", $parts[0]) : array();
        $response->setHeaders($headerLines);

        if ($response->getHttpCode() != 200) {
            $this->errorLogger->log("http response code: " . $response->getHttpCode());
            return false;
        }

        if (!isset($parts[1])) {
            $this->errorLogger->log("no response body found");
            return false;
        }

        if ($response->getHeader('Transfer-Encoding') == 'chunked') {
            $content = $this->parseChunkedContent($parts[1]);
        } else {
            $content = $parts[1];
        }
        $response->setBody($content);

        return $response;
    }
}

class HeaderFunctionProvider {
    /**
     * @var ResponseInterface
     */
    private $response;

    public function __construct(ResponseInterface $response) {
        $this->response = $response;
    }

    public function headerFunction($res, $rawHeader) {
        $this->response->appendHeader($rawHeader);
        return strlen($rawHeader);
    }
}

class CurlGetter implements RequestGetterInterface
{
    /**
     * @var ErrorLoggerInterface
     */
    private $errorLogger;

    public function __construct(ErrorLoggerInterface &$errorLogger) {
        $this->errorLogger = $errorLogger;
    }

    private function getOptions($url, $verify_peer, ResponseInterface $response) {
        $options = array(
            CURLOPT_HEADER => false,
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => CONNECT_TIMEOUT,
            CURLOPT_HTTPHEADER => array(
                'User-Agent: ' . @$_SERVER['HTTP_USER_AGENT'],
                'Referer: ' . @$_SERVER['HTTP_REFERER'],
                'X-Forwarded-For: ' . @((!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR']),
                'Accept-Language: ' . @$_SERVER['HTTP_ACCEPT_LANGUAGE'],
                'Exo-Script-Version: ' . SCRIPT_VERSION,
            )
        );
        if (!$verify_peer) {
            // for self-signed certificates (testing)
            $options[CURLOPT_SSL_VERIFYPEER] = 0;
        }
        $headerFunctionProvider = new HeaderFunctionProvider($response);
        $options[CURLOPT_HEADERFUNCTION] = array($headerFunctionProvider, 'headerFunction');
        return $options;
    }

    public function resolveMulti(array $urls, $verify_peer = true)
    {
        $responses = array();
        if (!ALLOW_MULTI_CURL || !function_exists('curl_multi_exec')) {
            foreach ($urls as $url) {
                $responses[] = $this->resolve($url, $verify_peer);
            }
            return $responses;
        }

        $multi_curl = curl_multi_init();
        $handles = array();
        foreach ($urls as $url) {
            $response = new SimpleHttpResponse();
            $responses[] = $response;
            $curl = curl_init();
            curl_setopt_array($curl, $this->getOptions($url, $verify_peer, $response));
            curl_multi_add_handle($multi_curl, $curl);
            $handles[] = $curl;
        }

        $active = null;
        do {
            $mrc = curl_multi_exec($multi_curl, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($multi_curl) == -1) {
                usleep(1);
            }
            do {
                $mrc = curl_multi_exec($multi_curl, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }
        foreach ($handles as $key => $handle) {
            if (($error = curl_error($handle)) !== '') {
                $this->errorLogger->log("curl failed: " . $error);
                $responses[$key] = false;
            } else {
                $responses[$key]->setBody(curl_multi_getcontent($handle));
            }
            curl_multi_remove_handle($multi_curl, $handle);
            curl_close($handle);
        }
        curl_multi_close($multi_curl);
        return $responses;
    }

    /**
     * @param $url
     * @param bool $verify_peer
     * @return bool|ResponseInterface
     */
    public function resolve($url, $verify_peer = true)
    {
        $response = new SimpleHttpResponse();
        $options = $this->getOptions($url, $verify_peer, $response);

        $curl = curl_init();
        curl_setopt_array($curl, $options);
        $response->setBody(curl_exec($curl));

        if (($error = curl_error($curl)) !== '') {
            curl_close($curl);
            $this->errorLogger->log("curl failed: " . $error);
            return false;
        }
        $info = curl_getinfo($curl);
        curl_close($curl);

        if ($info["http_code"] != 200) {
            $this->errorLogger->log("http response code: " . $info["http_code"]);
            return false;
        }
        return $response;
    }
}

class ArrayErrorLogger implements ErrorLoggerInterface
{
    protected $errors = array();

    public function log($error) {
        $trace = debug_backtrace();
        $this->errors[] = ((isset($trace[1]["function"])) ? $trace[1]["function"] . " -- " : "" ) . $error;
    }

    public function getErrors() {
        return $this->errors;
    }
}

class XcacheCache implements CacheInterface {
    public function get($key)
    {
        if (xcache_isset($key)) {
            return xcache_get($key);
        }
        return false;
    }

    public function set($key, $value, $ttl)
    {
        return xcache_set($key, $value, $ttl);
    }

    public function delete($key)
    {
        return xcache_unset($key);
    }

    public function increment($key, $step)
    {
        return xcache_inc($key, $step);
    }

    public function decrement($key, $step)
    {
        return xcache_dec($key, $step);
    }
}

class ApcCache implements CacheInterface {
    public function get($key)
    {
        $res = false;
        $data = apc_fetch($key, $res);
        return ($res) ? $data : false;
    }

    public function set($key, $value, $ttl)
    {
        return apc_store($key, $value, $ttl);
    }

    public function delete($key)
    {
        return apc_delete($key);
    }

    public function increment($key, $step)
    {
        return apc_inc($key, $step);
    }

    public function decrement($key, $step)
    {
        return apc_dec($key, $step);
    }
}

/**
 * @param $errorLogger
 * @return RequestGetterInterface
 */
function createRequestGetter($errorLogger)
{
    if (!in_array('curl', get_loaded_extensions())) {
        //Here we get the content with fsockopen
        $getter = new FsockGetter($errorLogger);
    } else {
        //Here we get the content with cURL
        $getter = new CurlGetter($errorLogger);
    }
    return $getter;
}

/**
 * @return CacheInterface
 */
function getCacheInstance() {
    if (extension_loaded('xcache')) {
        return new XcacheCache();
    } elseif (extension_loaded('apc')) {
        return new ApcCache();
    } else {
        return false;
    }
}

function getAction($type)
{
    switch ($type) {
        case 'popunder':
            return 'actionPopunder';
        case 'banner':
            return 'actionMultiBanner';
        default:
            return false;
    }
}

function filterRequestParams($allowedParams, $request) {
    $passedParams = array();
    foreach ($allowedParams as $paramName) {
        if (!empty($request[$paramName])) {
            $passedParams[$paramName] = $request[$paramName];
        }
    }
    return $passedParams;
}

function buildUrl($base, array $params)
{
    $url = $base;
    $params['user_ip'] = @$_SERVER['REMOTE_ADDR'];
    $url .= '&' . http_build_query($params);
    return $url;
}

function actionPopunder(array $requestData)
{
    global $errorLogger, $allowedParams;

    $urls = array();
    foreach ($requestData as $key => $request) {
        $request = filterRequestParams($allowedParams, $request);
        $urls[] = buildUrl(POPUNDER_RESOURCE_URL, $request);
    }
    $errorLogger->log($urls);
    $getter = createRequestGetter($errorLogger);

    $cache = getCacheInstance();
    foreach ($urls as $key => $url) {
        if ($cache && CACHE_INTERVAL_SCRIPTS > 0) {
            if ($body = $cache->get(CACHE_PREFIX . $url)) {
                echo $body . "\n";
                unset($urls[$key]);
            }
        }
    }
    if (!empty($urls)) {
        $responses = $getter->resolveMulti($urls, VERIFY_PEER);
        $index = 0;
        foreach ($responses as $response) {
            $index++;
            if (!$response) {
                continue;
            }
            $body = $response->getBody();
            if ($cache && CACHE_INTERVAL_SCRIPTS > 0) {
                $cache->set(CACHE_PREFIX . $urls[$index - 1], $body, CACHE_INTERVAL_SCRIPTS);
            }
            echo $body . "\n";
        }
    }
}

function actionMultiBanner(array $requestData)
{
    global $errorLogger, $allowedParams;

    header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
    header("Cache-Control: no-cache, must-revalidate");
    header("Pragma: no-cache");

    $multiRequest = array();
    foreach ($requestData as $key => $request) {
        $requestParams = filterRequestParams($allowedParams, $request);
        if (isset($request['width']) && isset($request['height'])) {
            $requestParams['type'] = $request['width'] . "x" . $request['height'];
        }
        $multiRequest['zones'][] = $requestParams;
    }

    $url = buildUrl(MULTI_BANNER_RESOURCE_URL, $multiRequest);
    $errorLogger->log($url);
    $getter = createRequestGetter($errorLogger);
    $response = $getter->resolve($url, VERIFY_PEER);
    $imageTypes = array('image/gif', 'image/png', 'image/jpeg');

    $images = array();
    $image_keys = array();
    $results = array();
    $i = 0;
    if ($response) {
        $zones_data = json_decode($response->getBody(), true);
        if ($zones_data && is_array($zones_data))
        {
            foreach ($zones_data as $id => $zone) {
                $results["zones"][$id] = false;
                if (empty($zone["imgurl"])) {
                    $errorLogger->log("Empty imgurl for zone #" . $id);
                    continue;
                }
                if (empty($zone["url"])) {
                    $errorLogger->log("Empty url for zone #" . $id);
                    continue;
                }
                $zone['imgurl'] = str_replace(' ', '%20', $zone['imgurl']); // fix for spaces in urls
                if (!isset($image_keys[$zone["imgurl"]])) {
                    $image_keys[$zone["imgurl"]] = $i;
                    $i++;
                }
                $key = $image_keys[$zone["imgurl"]];
                $results["zones"][$id] = array(
                    'dest' => $zone["url"],
                    'img_key' => $key,
                );
            }
        }

        $image_urls = array_keys($image_keys);
        //try cache
        $cache = getCacheInstance();
        if ($cache && CACHE_INTERVAL_BANNERS > 0) {
            foreach($image_urls as $key => $url) {
                if ($img = $cache->get(CACHE_PREFIX . $url)) {
                    $images[$key] = $img;
                    unset($image_urls[$key]); // We now don't need to request it
                }
            }
        }
        if (!empty($image_urls)) {
            $imageResponses = $getter->resolveMulti($image_urls, VERIFY_PEER);
        }
        $index = 0;
        foreach ($image_urls as $key => $url) {
            if (!isset($imageResponses[$index])) {
                $errorLogger->log("less responses than requests");
            }
            $img = $imageResponses[$index];
            $index++;
            if ($img && in_array($img->getHeader('Content-Type'), $imageTypes)) {
                $images[$key]['img'] = base64_encode($img->getBody());
                $images[$key]['content_type'] = $img->getHeader('Content-Type');
                if ($cache && CACHE_INTERVAL_BANNERS > 0) {
                    $ctr_key = CACHE_PREFIX . 'banner_key_counter';
                    if (CACHE_KEYS_LIMIT_BANNERS > 0) {
                        $cache_ctr = $cache->get($ctr_key);
                        if ($cache_ctr == false) {
                            $cache->set($ctr_key, 0, CACHE_INTERVAL_BANNERS);
                        }
                        if ($cache_ctr < CACHE_KEYS_LIMIT_BANNERS) {
                            $cache_ctr = $cache->increment($ctr_key, 1);
                        }
                    }
                    if (CACHE_KEYS_LIMIT_BANNERS > 0 && $cache_ctr <= CACHE_KEYS_LIMIT_BANNERS) {
                        $cache->set(CACHE_PREFIX . $url, $images[$key], CACHE_INTERVAL_BANNERS);
                    } else {
                        $errorLogger->log('Cache key number limit reached');
                    }
                }
            }
        }
        $results["images"] = $images;

        echo "ExoLoader.renderBannerZones(" . json_encode($results) . ");\n";
    }
}

$allowedParams = array('idzone', 'cat', 'sub');

$request = array();
$adZones = (!empty($_COOKIE[ADS_COOKIE_NAME])) ? json_decode($_COOKIE[ADS_COOKIE_NAME], true) : '';
if (is_array($adZones)) {
    $request = array_merge($adZones, $request);
}

$errorLogger = new ArrayErrorLogger();
 foreach ($request as $type => $data) {
     $action = getAction($type);
     if ($action) {
         $action($data);
     } else {
         $errorLogger->log('Unknown action ' . $type);
     }
 }

if (!empty($_REQUEST['exoDebug']) && $_REQUEST['exoDebug'] == 'exoDebug') {
    $errors = $errorLogger->getErrors();
    if (!empty($errors)) {
        echo "<pre>";
        echo "Script version: " . SCRIPT_VERSION . "\n";
        print_r($errors);
        echo "</pre>";
    }
}