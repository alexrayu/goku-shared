<?php

use App\Kernel;

// Served through the root .htaccess fallback (URL without /public): the base path is the project
// folder, not public/. Without this, a site in a subfolder matches no route.
$script = $_SERVER['SCRIPT_NAME'] ?? '';
$public = dirname($script);
$uri = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
if ('/public' === substr($public, -7) && $uri !== $public && !str_starts_with($uri, $public.'/')) {
    $_SERVER['SCRIPT_NAME'] = rtrim(dirname($public), '/').'/'.basename($script);
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
