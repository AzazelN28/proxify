<?php

class Response {
  const STATUS_EXPECTING_HTTP = 0;
  const STATUS_EXPECTING_HEADERS = 1;
  const STATUS_EXPECTING_HTTP_OR_BODY = 2;

  static public function getResponses($result) {

    $responses = [];
    $response = null;
    $lines = explode("\n", $result);

    $status = self::STATUS_EXPECTING_HTTP;
    foreach ($lines as $index => $line) {
      if ($status === self::STATUS_EXPECTING_HTTP || $status === self::STATUS_EXPECTING_HTTP_OR_BODY) {
        if (preg_match('/^(?P<protocol>HTTP\/[0-9]\.[0-9]) (?P<status>[0-9]{3}) (?P<statusText>.*?)$/', $line, $matches)) {
          if (!is_null($response)) {
            $responses[] = $response;
          }
          $response = new Response();
          $response->protocol = $matches['protocol'];
          $response->status = $matches['status'];
          $response->statusText = $matches['statusText'];

          $status = self::STATUS_EXPECTING_HEADERS;
        } else {
          if ($status === self::STATUS_EXPECTING_HTTP) {
            throw new Exception("Expecting HTTP, read '${line}'");
          } else {
            break;
          }
        }
      } else if ($status === self::STATUS_EXPECTING_HEADERS) {
        if (preg_match('/^(?P<name>.*?): (?P<value>.*?)$/', $line, $matches)) {

          $name = $matches['name'];
          $value = $matches['value'];

          $response->headers[$name] = $value;

        } else {

          $trimmed = trim($line);
          if (empty($trimmed)) {
            $status = self::STATUS_EXPECTING_HTTP_OR_BODY;
          } else {
            throw new Exception("Expecting headers, read '${line}'");
          }

        }
      }
    }

    $responses[] = $response;
    if ($status === self::STATUS_EXPECTING_HTTP_OR_BODY) {
      $response->body = implode("\n", array_slice($lines, $index));
    }

    return $responses;

  }

  private $protocol = 'HTTP/1.1',
          $status = 200,
          $statusText = 'OK',
          $headers = [],
          $body = '';

  public function setStatus($status) {
    static $statusTexts = array(
      100 => 'Continue',
      101 => 'Switching Protocols',

      200 => 'OK',
      201 => 'Created',
      202 => 'Accepted',
      203 => 'Non-Authoritative Information',
      204 => 'No Content',
      205 => 'Reset Content',
      206 => 'Partial Content',

      300 => 'Multiple Choices',
      301 => 'Moved Permanently',
      302 => 'Found',
      303 => 'See Other',
      304 => 'Not Modified',
      305 => 'Use Proxy',
      307 => 'Temporary Redirect',

      400 => 'Bad Request',
      401 => 'Unauthorized',
      402 => 'Payment Required',
      403 => 'Forbidden',
      404 => 'Not Found',
      405 => 'Method Not Allowed',
      406 => 'Not Acceptable',
      407 => 'Proxy Authentication Required',
      408 => 'Request Timeout',
      409 => 'Conflict',
      410 => 'Gone',
      411 => 'Length Required',
      412 => 'Precondition Failed',
      413 => 'Request Entity Too Large',
      414 => 'Request-URI Too Long',
      415 => 'Unsupported Media Type',
      416 => 'Request Range Not Satisfiable',
      417 => 'Expectation Failed',

      500 => 'Internal Server Error',
      501 => 'Not Implemented',
      502 => 'Bad Gateway',
      503 => 'Service Unavailable',
      504 => 'Gateway Timeout',
      505 => 'HTTP Version Not Supported'
    );

    $this->status = $status;
    $this->statusText = $statusTexts[$status];
  }

  public function getStatus() {
    return $this->status;
  }

  public function getHeader($name) {
    return $this->headers[$name];
  }

  public function setHeader($name,$value) {
    $this->headers[$name] = $value;
    return $this;
  }

  public function hasHeader($name) {
    return isset($this->headers[$name]);
  }

  public function removeHeader($name) {
    unset($this->headers[$name]);
    return $this;
  }

  public function getContentType() {
    return $this->getHeader('Content-Type');
  }

  public function hasContentType($type) {
    if (!$this->hasHeader('Content-Type')) {
      return false;
    }
    $type = str_replace('/','\\/', $type);
    $contentType = $this->getHeader('Content-Type');
    return preg_match("/^$type/i", $contentType);
  }

  public function getBody() {
    return $this->body;
  }

  public function setBody($newBody) {
    $this->body = $newBody;
    return $this;
  }

  public function getProxifiedUrl($url, $baseUrl, $replaceUrl) {
    if (preg_match('/^https?:\/\//', $url)) {
      return $replaceUrl . '/?url=' . $url;
    } else if (preg_match('/^\/\//', $url)) {
      $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
      return $replaceUrl . '/?url=' . $scheme . ':' . $url;
    } else if (preg_match('/^\//', $url)) {
      return "$replaceUrl/?url=$baseUrl$url";
    }
    return "$replaceUrl/?url=$baseUrl/$url";
  }

  public function getHtmlProxifiedUrl($url, $replaceUrl) {
    return function($matches) use ($url, $replaceUrl) {
      return $matches['attribute'] . '=' . $matches['quote'] . $this->getProxifiedUrl($matches['url'], $url, $replaceUrl) . $matches['quote'];
    };
  }

  public function getCssProxifiedUrl($url, $replaceUrl) {
    return function($matches) use ($url, $replaceUrl) {
      if (substr($url,0,1) === '"' || substr($url,0,1) === "'") {
        $url = substr($url,1,-1);
      }

      $proxifiedUrl = $this->getProxifiedUrl($matches['url'], $url, $replaceUrl);
      return "url($proxifiedUrl)";
    };
  }

  public function proxify($url, $replaceUrl) {
    if ($this->hasHeader('Connection')) {
      $this->removeHeader('Connection');
    }
    if ($this->hasHeader('Content-Encoding')) {
      $this->removeHeader('Content-Encoding');
    }
    if ($this->hasHeader('Transfer-Encoding')) {
      $this->removeHeader('Transfer-Encoding');
    }
    if ($this->hasHeader('X-Frame-Options')) {
      $this->removeHeader('X-Frame-Options');
    }

    if ($this->hasContentType('text/html')) {
      $body = $this->getBody();
      $body = preg_replace_callback('/(?<attribute>src|href)=(?<quote>"|\')(?<url>.*?)\\2/', $this->getHtmlProxifiedUrl($url, $replaceUrl), $body);
      $this->setBody($body);
    } else if ($this->hasContentType('text/css')) {
      $body = $this->getBody();
      $body = preg_replace_callback('/url\((?P<url>.*?)\)/', $this->getCssProxifiedUrl($url, $replaceUrl), $body);
      $this->setBody($body);
    }
  }

  public function send() {
    header("X-Powered-By: ROJO 2 Proxify");
    header("$this->protocol $this->status $this->statusText");
    foreach($this->headers as $name => $value) {
      header("${name}: ${value}");
    }
    echo $this->body;
  }
}

