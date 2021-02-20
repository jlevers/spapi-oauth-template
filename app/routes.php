<?php

require_once __DIR__ . "/../vendor/autoload.php";

use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return function (App $app) {
    $DEBUG = $_ENV["DEBUG"] === "true";

    // Fill me in!
};
