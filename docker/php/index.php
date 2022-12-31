<?php

use Packeton\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

// X_FORWARDED_PROTO is always trusted
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS']='on';
}

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
