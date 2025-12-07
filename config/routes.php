<?php
declare(strict_types=1);

/**
 * Route definitions
 * 
 * This file defines all the routes for the application.
 */

use App\Controllers\HomeController;
use App\Controllers\AuthController;
use App\Controllers\MovieController;
use App\Controllers\UserController;
use App\Controllers\WatchlogController;

// Home routes
$router->get('/', [HomeController::class, 'index']);

// Watchlog
$router->get('/watchlog', [WatchlogController::class, 'index']);

// Authentication routes
$router->get('/login', [AuthController::class, 'showLoginForm']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/register', [AuthController::class, 'showRegistrationForm']);
$router->post('/register', [AuthController::class, 'register']);
$router->get('/logout', [AuthController::class, 'logout']);

// Movie routes
$router->get('/movies/search', [MovieController::class, 'search']);
$router->post('/movies/search', [MovieController::class, 'search']);
$router->get('/movies/{id}', [MovieController::class, 'show']);
$router->post('/movies/{id}/add', [MovieController::class, 'addToList']);
$router->post('/movies/{id}/remove', [MovieController::class, 'removeFromList']);
// Poster upload/replace (Owned Media)
$router->post('/movies/{id}/poster', [MovieController::class, 'uploadPoster']);
// Per-movie refresh from TMDb
$router->post('/movies/{id}/refresh', [MovieController::class, 'refreshSingleMovie']);
// Watched log
$router->post('/movies/{id}/watched', [MovieController::class, 'markWatched']);
$router->get('/movies/{id}/watched-log', [MovieController::class, 'getWatchedLog']);
// Delete a watched log entry (use POST for compatibility)
$router->post('/watched/{id}/delete', [MovieController::class, 'deleteWatchedLog']);
// Edit/update a watched log entry (use POST for compatibility)
$router->post('/watched/{id}/edit', [MovieController::class, 'updateWatchedLog']);

// List routes
$router->get('/collection', [MovieController::class, 'collection']);
$router->get('/to-watch', [MovieController::class, 'toWatch']);
$router->get('/to-buy', [MovieController::class, 'toBuy']);
$router->post('/collection/refresh', [MovieController::class, 'refreshMovieData']);

// User settings
$router->get('/settings', [UserController::class, 'showSettings']);
$router->post('/settings', [UserController::class, 'updateSettings']);
$router->post('/settings/display-preferences', [UserController::class, 'updateDisplayPreferences']);

// Movie formats
$router->post('/movies/{id}/formats', [MovieController::class, 'updateFormats']); 