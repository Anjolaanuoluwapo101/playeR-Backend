<?php
class Router {
    private static $routes = [];

    public static function get($route, $callback) {
        self::$routes['GET'][$route] = $callback;
    }

    public static function post($route, $callback) {
        self::$routes['POST'][$route] = $callback;
    }

    public static function dispatch() {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'];

        if (isset(self::$routes[$method][$uri])) {
            call_user_func(self::$routes[$method][$uri]);
        } else {
            http_response_code(404);
            echo "404 Not Found";
        }
    }
}