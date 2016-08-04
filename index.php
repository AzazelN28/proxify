<?php

require_once 'http/Response.php';
require_once 'http/Request.php';

require_once 'config.php';

if (defined('PROXIFY_LOG_LEVEL')) {
  define('DEBUG', true);
}

function debug($m) {
  if (defined('PROXIFY_LOG_LEVEL') && defined('PROXIFY_LOG_FILE')) {
    $d = date('Y-m-d H:i:s');
    return file_put_contents(constant('PROXIFY_LOG_FILE'), "$d - $m\n", FILE_APPEND);
  }
}

function getMethod() {
  global $argv;
  if (isCommandLineInterface() && isset($argv[1])) {
    return $argv[1];
  }

  if (isset($_SERVER['REQUEST_METHOD'])) {
    return $_SERVER['REQUEST_METHOD'];
  }

  return 'GET';
}

function getUrl() {
  global $argv;
  if (isCommandLineInterface() && isset($argv[2])) {
    return $argv[2];
  }

  if (isset($_GET['url'])) {
    // Prevenimos de proxificar el proxificador (así el proxificador que lo
    // desproxifique, buen desproxificador será).
    if (preg_match('/^' . str_replace('/', '\\/', PROXIFY_URL) . '/', $_GET['url'])) {
      return null;
    }
    return $_GET['url'];
  }
  return null;
}

function isCommandLineInterface() {
  return (php_sapi_name() === 'cli');
}

function getCacheDir() {
  return __DIR__ . DIRECTORY_SEPARATOR . 'cache';
}

// @see: http://stackoverflow.com/questions/478121/php-get-directory-size
function getCacheSize() {
  $f = getCacheDir();
  $io = popen('/usr/bin/du -sb '.$f, 'r');
  $size = fgets($io,4096);
  $size = substr($size,0,strpos($size,"\t"));
  pclose($io);
  return intval($size);
}

function isCacheSizeExceeded() {
  $size = getCacheSize();
  $maxSize = defined('PROXIFY_CACHE_SIZE') ? constant('PROXIFY_CACHE_SIZE') : 1024 * 1024;
  if ($size > $maxSize) {
    debug("Cache size exceeded ($size), cleaning cache...");
    return true;
  }
  return false;
}

function isCacheEnabled() {
  $result = defined('PROXIFY_CACHE') && constant('PROXIFY_CACHE') === true;
  if ($result) {
    debug("Cache enabled");
  }
  return $result;
}

function getCacheUrlPath($url) {
  return resolveCachePath(sha1($url));
}

function resolveCachePath($path) {
  if (is_array($path)) {
    $path = implode(DIRECTORY_SEPARATOR, $path);
  }
  return getCacheDir() . DIRECTORY_SEPARATOR . $path;
}

function isCached($url) {
  return is_file(getCacheUrlPath($url));
}

function isCachePathExpired($path) {
  return (time() - filemtime($path)) > PROXIFY_CACHE_EXPIRATION;
}

function isCacheExpired($url) {
  $path = getCacheUrlPath($url);
  $result = isCachePathExpired($path);
  if ($result) {
    debug("File $path has expired");
  }
  return $result;
}

function cleanCache() {
  foreach(glob(resolveCachePath('*')) as $cacheFile) {
    debug("Checking if $cacheFile has expired...");
    if (isCachePathExpired($cacheFile)) {
      debug("Expired, removing $cacheFile");
      unlink($cacheFile);
    }
  }
}

function setCacheUrl($url, $content) {
  $path = getCacheUrlPath($url);
  debug("Saved cache file $path");
  file_put_contents($path, $content);
}

function getCacheUrl($url) {
  return file_get_contents(getCacheUrlPath($url));
}

function proxify($method, $url) {
  if (isCacheEnabled()) {
    if (isCacheSizeExceeded()) {
      cleanCache();
    }

    if (isCached($url)) {
      if (!isCacheExpired($url)) {
        $response = new Response();
        $response->setStatus(200);
        $response->setBody(getCacheUrl($url));
        $response->send();
        exit();
      }
    }
  }

  $request = new Request($method, $url);
  if (defined('PROXIFY_COOKIE_FILE')) {
    $request->setCookieFile(constant('PROXIFY_COOKIE_FILE'));
  }

  $request->setHeader('Host', parse_url($url, PHP_URL_HOST));
  $request->setHeader('Referer', $url);
  if (isCommandLineInterface()) {
    $except = array(
      'HTTP_HOST',
      'HTTP_CONTENT_LENGTH',
      'HTTP_CONTENT_TYPE'
    );

    foreach($_SERVER as $name => $value) {
      if (substr($name,0,5) === 'HTTP_' && !in_array($name, $except)) {
        $hname = str_replace(' ','-',ucwords(str_replace('_',' ',strtolower(substr($name,5)))));
        $request->setHeader($hname, $value);
      }
    }
  }

  if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
    $username = $_SERVER['PHP_AUTH_USER'];
    $password = $_SERVER['PHP_AUTH_PW'];
    $request->setUsername($_SERVER['PHP_AUTH_USER']);
    $request->setPassword($_SERVER['PHP_AUTH_PW']);
  }

  $responses = $request->execute();
  if ($responses !== false) {
    $response = end($responses);
    if (isCacheEnabled()) {
      setCacheUrl($url, $response->getBody());
    }

    $host = parse_url($url, PHP_URL_HOST);
    $response->proxify($url, PROXIFY_URL);
    $response->setHeader('Access-Control-Allow-Origin', '*');
    $response->setHeader('X-Proxified-URL', $url);
    $response->setHeader('X-Proxified-Host', $host);
    $response->send();
  } else {
    $response = new Response();
    $response->setStatus(500);
    $response->send();
  }
}

if (isCommandLineInterface()) {
  echo getCacheSize();
}

$method = getMethod();
$url = getUrl();

if (isset($method) && isset($url)) {
  if (!empty($method) && !empty($url)) {
    proxify($method, $url);
    exit();
  }
}

if (isCommandLineInterface()) {
  exit();
}
?>
<!doctype html>
<html>
  <head>
    <meta charset="utf-8">
    <title>Proxify</title>
    <link href="https://fonts.googleapis.com/css?family=Lato:400,300" rel="stylesheet" type="text/css">
    <style type="text/css">

      html, body {
        font-family: Lato, sans-serif;
        font-size: 32px;
        margin: 0px;
        padding: 0px;
        width: 100%;
        height: 100%;
        background: #000;
        background: linear-gradient(to bottom, #076, #067);
        color: #fff;
      }

      .proxify {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        height: 100%;
      }

      .proxify-form {
        min-width: 640px;
        display: flex;
        align-items: center;
      }

      .proxify-form > label,
      .proxify-form > input,
      .proxify-form > button {
        font-family: Lato, sans-serif;
        font-weight: 300;
      }

      .proxify-form > label {
        margin-right: 16px;
      }

      .proxify-form > input,
      .proxify-form > button {
        border: 0;
        font-size: 16px;
        padding: 16px;
      }

      .proxify-form > input:focus,
      .proxify-form > button:focus {
        outline: 1px solid #0fc;
      }

      .proxify-form > input {
        flex: 1 1 auto;
      }

      .proxify-form > input.error {
        background: #fee;
      }

      .proxify-form > input.error:focus {
        outline: 1px solid #f00;
      }

      .proxify-form > input.ok {
        background: #efe;
      }

      .proxify-form > input.ok:focus {
        outline: 1px solid #0f0;
      }

      .proxify-form > button {
        background: #096;
        color: #fff;
        transition: .2s ease-out all;
      }

      .proxify-form > button:hover {
        background: #0b8;
      }

    </style>
  </head>
  <body>
    <div class="proxify">
      <form class="proxify-form">
        <label for="url">Proxify</label>
        <input type="text" name="url" id="url" placeholder="Introduce aquí la URL" />
        <button type="submit">
          Acceder
        </button>
      </form>
      <script>

        function handle(e) {
          if (this.value.replace(/^\s+|\s+$/,"") === "") {
            this.className = "";
          } else {
            if (!/https?:\/\/.+/.test(this.value)) {
              this.className = "error";
            } else {
              this.className = "ok";
            }
          }
        }

        var input = document.querySelector("input");
        input.addEventListener("input", handle);
        input.addEventListener("paste", handle);

      </script>
    </div>
  </body>
</html>

