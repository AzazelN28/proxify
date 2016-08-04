<?php

class Request {

  private $method,
          $url,
          $headers = array(),
          $cookieFile = 'cookies.dat',
          $username,
          $password;

  public function __construct($method, $url) {
    $this->method = $method;
    $this->url = $url;
  }

  public function setHeader($name, $value) {
    $this->headers[] = "$name: $value";
    return $this;
  }

  public function setUsername($username) {
    $this->username = $username;
    return $this;
  }

  public function setPassword($password) {
    $this->password = $password;
    return $this;
  }

  public function setCookieFile($file) {
    $this->cookieFile = $file;
    return $this;
  }

  public function execute() {
    $url = $this->url;
    $method = $this->method;
    $headers = $this->headers;

    if (($handler = curl_init($url)) !== false) {

      curl_setopt($handler, CURLOPT_HEADER, true);
      curl_setopt($handler, CURLOPT_FOLLOWLOCATION, true);
      curl_setopt($handler, CURLOPT_RETURNTRANSFER, true);
      if (!empty($this->cookieFile)) {
        curl_setopt($handler, CURLOPT_COOKIEFILE, $this->cookieFile);
        curl_setopt($handler, CURLOPT_COOKIEJAR, $this->cookieFile);
      }
      curl_setopt($handler, CURLOPT_SSL_VERIFYPEER, false);

      if (!empty($this->username) && !empty($this->password)) {
        $username = $this->username;
        $password = $this->password;
        curl_setopt($handler, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($handler, CURLOPT_USERPWD, "$username:$password");
      }
      /*else if (isset($_SERVER['PHP_AUTH_DIGEST'])) {
        curl_setopt($handler, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
        curl_setopt($handler,
      }*/


      // seteamos el método.
      curl_setopt($handler, CURLOPT_CUSTOMREQUEST, $method);
      if ($method === 'POST') {
        curl_setopt($handler, CURLOPT_POSTFIELDS, $_POST);
      }

      $host = parse_url($url, PHP_URL_HOST);
      curl_setopt($handler, CURLOPT_HTTPHEADER, array_merge($headers, array(
        "Host: $host",
        "Referer: $url"
      )));

      $result = curl_exec($handler);
      if ($result !== false) {
        // Obtenemos las respuestas.
        $responses = Response::getResponses($result);
      }
      curl_close($handler);
    }

    if (isset($responses)) {
      return $responses;
    }
    return false;
  }

}
