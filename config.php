<?php

/**
 * This function let us overwrite every configuration param
 * using environment variables.
 */
function defineParam($name, $defaultValue) {
  if (isset($_ENV[$name])) {
    define($name, $_ENV[$name]);
  } else {
    define($name, $defaultValue);
  }
}

defineParam('PROXIFY_URL', 'http://localhost:9000');
defineParam('PROXIFY_LOG_LEVEL', true); // TODO: Create different logging levels
defineParam('PROXIFY_LOG_FILE', 'proxify.log');
defineParam('PROXIFY_CACHE', true);
defineParam('PROXIFY_CACHE_EXPIRATION', 10);
defineParam('PROXIFY_CACHE_SIZE', 1024 * 1024 * 2);
defineParam('PROXIFY_COOKIE_FILE', 'cookies.dat');
