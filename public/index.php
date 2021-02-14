<?php

require_once __DIR__ . "/../vendor/autoload.php";

use DI\Container;
use Dotenv\Dotenv;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

// Load environment variables from ../.env
$dotenv = Dotenv::createImmutable(__DIR__ . "/..");
$dotenv->load();
// Boolean values in .env are loaded as strings
$DEBUG = $_ENV["DEBUG"] === "true";

$container = new Container();
AppFactory::setContainer($container);

$container->set("view", function() {
    return Twig::create(__DIR__ . "/../public/html");
});

// Create app
$app = AppFactory::create();

// Register routes
$routes = require __DIR__ . '/../app/routes.php';
$routes($app);

// Add middleware
$app->add(TwigMiddleware::createFromContainer($app));
$app->addRoutingMiddleware();
$app->addErrorMiddleware($DEBUG, true, true);

// Run app
$app->run();
