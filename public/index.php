<?php
/**
 * OwnMapsTimeline — Front Controller
 * All requests route here via nginx try_files.
 */

declare(strict_types=1);

// ── Bootstrap ───────────────────────────────────────────────────────────────
require_once __DIR__ . '/../src/config/database.php';
require_once __DIR__ . '/../src/lib/View.php';

// ── Route parsing ───────────────────────────────────────────────────────────
$method  = $_SERVER['REQUEST_METHOD'];
$uri     = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query   = $_GET;

// Strip trailing slash (except root)
if ($uri !== '/' && str_ends_with($uri, '/')) {
    $uri = rtrim($uri, '/');
    header('Location: ' . $uri . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
    exit;
}

// ── Routing table ───────────────────────────────────────────────────────────
$routes = [
    // Public views
    'GET' => [
        '/login' => 'AuthController::loginForm',
        '/setup' => 'AuthController::setupForm',
        // Protected views
        '/'       => 'protected:MapController::dashboard',
        '/dashboard' => 'protected:MapController::dashboard',
        '/devices'   => 'protected:DeviceController::list',
        '/users'     => 'protected:UserController::list',
        '/import'    => 'protected:ImportController::form',
        '/places'    => 'protected:PlaceController::list',
        // API (protected)
        '/api/locations' => 'protected:ApiController::locations',
        '/api/places'        => 'protected:PlaceController::apiList',
        '/api/places/status' => 'protected:PlaceController::status',
        '/api/places/log'    => 'protected:PlaceController::log',
        '/api/places/debug'    => 'protected:PlaceController::debugLog',
        '/api/places/cron-log' => 'protected:PlaceController::cronLog',
        '/api/poi-image' => 'protected:ApiController::poiImage',
        '/api/shared-locations' => 'protected:ApiController::sharedLocations',
        '/api/device-config' => 'ApiController::deviceConfig', // public: validated by tid+token
    ],
    'POST' => [
        '/login'  => 'AuthController::login',
        '/setup'  => 'AuthController::setup',
        '/logout' => 'AuthController::logout',
        // Webhook (no auth — validated by tid+token)
        '/webhook' => 'WebhookController::ingest',
        // Protected POST
        '/devices/create' => 'protected:DeviceController::create',
        '/devices/update' => 'protected:DeviceController::update',
        '/devices/config' => 'protected:DeviceController::updateConfig',
        '/devices/delete' => 'protected:DeviceController::delete',
        '/devices/color'  => 'protected:DeviceController::updateColor',
        '/devices/share'      => 'protected:DeviceController::shareDevice',
        '/devices/unshare'    => 'protected:DeviceController::unshareDevice',
        '/devices/unshare-self' => 'protected:DeviceController::unshareSelf',
        '/devices/share-color' => 'protected:DeviceController::updateShareColor',
        '/users/create'   => 'protected:UserController::create',
        '/users/update'   => 'protected:UserController::update',
        '/users/delete'   => 'protected:UserController::delete',
        '/import/preview' => 'protected:ImportController::preview',
        '/import/execute' => 'protected:ImportController::execute',
        '/places/create'      => 'protected:PlaceController::create',
        '/places/rename'      => 'protected:PlaceController::rename',
        '/places/delete'      => 'protected:PlaceController::delete',
        '/places/recalculate' => 'protected:PlaceController::recalculate',
        '/places/settings'    => 'protected:PlaceController::saveSettings',
        '/api/places/detect'  => 'protected:PlaceController::detect',
    ],
];

// ── Route matching ──────────────────────────────────────────────────────────
$handler = $routes[$method][$uri] ?? null;

// Try dynamic routes for /places/{id}
if ($handler === null && $method === 'GET') {
    // /places/{id} → PlaceController::detail
    if (preg_match('#^/places/(\d+)$#', $uri, $m)) {
        $handler = 'protected:PlaceController::detail';
        $query['id'] = $m[1];
    }
}

if ($handler === null) {
    http_response_code(404);
    echo "<h1>404 Not Found</h1>";
    exit;
}

// ── Auth guard ──────────────────────────────────────────────────────────────
$needsAuth = str_starts_with($handler, 'protected:');
if ($needsAuth) {
    $handler = substr($handler, 10); 
    require_once __DIR__ . '/../src/controllers/AuthController.php';
    if (!AuthController::isAuthenticated()) {
        $_SESSION['redirect_after_login'] = $uri . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '');
        header('Location: /login', true, 302);
        exit;
    }
}

// ── Dispatch ────────────────────────────────────────────────────────────────
list($class, $method) = explode('::', $handler);

$controllerFile = __DIR__ . '/../src/controllers/' . $class . '.php';
if (!file_exists($controllerFile)) {
    http_response_code(501);
    echo "<h1>501 Not Implemented</h1>";
    echo "<p>Route <code>$handler</code> matched but <code>$class.php</code> not found yet.</p>";
    exit;
}

require_once $controllerFile;

// Check method exists
if (!method_exists($class, $method)) {
    http_response_code(501);
    echo "<h1>501 Not Implemented</h1>";
    echo "<p>Method <code>$class::$method()</code> not found.</p>";
    exit;
}

// Call the handler
$class::$method($query, json_decode(file_get_contents('php://input'), true) ?? []);
