<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use App\Services\LoggerService;
use App\Services\SessionService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

class AuthController extends Controller
{
    /**
     * Show the login form
     */
    public function showLoginForm(Request $request): Response
    {
        // Redirect to home if already logged in
        if (SessionService::isLoggedIn()) {
            return $this->redirect('/');
        }
        
        $this->logAction('showLoginForm');
        return $this->renderResponse('auth/login.twig', [
            'error' => null
        ]);
    }
    
    /**
     * Process login form submission
     */
    public function login(Request $request): Response
    {
        // Redirect to home if already logged in
        if (SessionService::isLoggedIn()) {
            $this->logAction('login', ['status' => 'already_logged_in']);
            return $this->redirect('/');
        }
        
        // Check CSRF token
        $token = $request->request->get('csrf_token');
        if (!$this->verifyCsrfToken($token)) {
            $this->logError('Invalid CSRF token during login', [
                'ip' => $request->getClientIp()
            ]);
            return $this->renderResponse('auth/login.twig', [
                'error' => 'Invalid request, please try again.',
            ]);
        }
        
        $username = $request->request->get('username');
        $password = $request->request->get('password');
        
        // Validate input
        if (empty($username) || empty($password)) {
            $this->logError('Empty username or password during login', [
                'username_empty' => empty($username),
                'password_empty' => empty($password),
                'ip' => $request->getClientIp()
            ]);
            return $this->renderResponse('auth/login.twig', [
                'error' => 'Username and password are required.',
                'username' => $username,
            ]);
        }
        
        // Attempt to authenticate
        $user = User::authenticate($username, $password);
        
        if (!$user) {
            $this->logError('Failed login attempt', [
                'username' => $username,
                'ip' => $request->getClientIp()
            ]);
            return $this->renderResponse('auth/login.twig', [
                'error' => 'Invalid username or password.',
                'username' => $username,
            ]);
        }
        
        // Log the user in
        SessionService::login($user);
        
        LoggerService::info('User logged in successfully', [
            'user_id' => $user->getId(),
            'username' => $user->getUsername(),
            'ip' => $request->getClientIp()
        ]);
        
        // Redirect to home page
        return $this->redirect('/');
    }
    
    /**
     * Show the registration form
     */
    public function showRegistrationForm(Request $request): Response
    {
        // Redirect to home if already logged in
        if (SessionService::isLoggedIn()) {
            return $this->redirect('/');
        }
        
        $this->logAction('showRegistrationForm');
        return $this->renderResponse('auth/register.twig', [
            'error' => null,
            'errors' => [],
            'username' => '',
            'email' => ''
        ]);
    }
    
    /**
     * Process registration form submission
     */
    public function register(Request $request): Response
    {
        // Redirect to home if already logged in
        if (SessionService::isLoggedIn()) {
            $this->logAction('register', ['status' => 'already_logged_in']);
            return $this->redirect('/');
        }
        
        // Check CSRF token
        $token = $request->request->get('csrf_token');
        if (!$this->verifyCsrfToken($token)) {
            $this->logError('Invalid CSRF token during registration', [
                'ip' => $request->getClientIp()
            ]);
            return $this->renderResponse('auth/register.twig', [
                'error' => 'Invalid request, please try again.',
            ]);
        }
        
        $username = $request->request->get('username');
        $email = $request->request->get('email');
        $password = $request->request->get('password');
        $passwordConfirm = $request->request->get('password_confirm');
        
        // Validate input
        $errors = [];
        
        if (empty($username)) {
            $errors['username'] = 'Username is required.';
        } elseif (strlen($username) < 3) {
            $errors['username'] = 'Username must be at least 3 characters.';
        } elseif (User::findByUsername($username)) {
            $errors['username'] = 'Username is already taken.';
        }
        
        if (empty($email)) {
            $errors['email'] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Email is invalid.';
        } elseif (User::findByEmail($email)) {
            $errors['email'] = 'Email is already registered.';
        }
        
        if (empty($password)) {
            $errors['password'] = 'Password is required.';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }
        
        if ($password !== $passwordConfirm) {
            $errors['password_confirm'] = 'Passwords do not match.';
        }
        
        // If there are errors, re-render the form
        if (!empty($errors)) {
            $this->logError('Registration validation failed', [
                'errors' => array_keys($errors),
                'ip' => $request->getClientIp()
            ]);
            return $this->renderResponse('auth/register.twig', [
                'errors' => $errors,
                'username' => $username,
                'email' => $email,
            ]);
        }
        
        try {
            // Create the user
            $user = User::create($username, $email, $password);
            
            if (!$user) {
                $this->logError('Failed to create user during registration', [
                    'username' => $username,
                    'email' => $email,
                    'ip' => $request->getClientIp()
                ]);
                return $this->renderResponse('auth/register.twig', [
                    'error' => 'There was a problem creating your account.',
                    'username' => $username,
                    'email' => $email,
                ]);
            }
            
            // Log the user in
            SessionService::login($user);
            
            LoggerService::info('User registered successfully', [
                'user_id' => $user->getId(),
                'username' => $user->getUsername(),
                'email' => $user->getEmail(),
                'ip' => $request->getClientIp()
            ]);
            
            // Redirect to home page
            return $this->redirect('/');
        } catch (\Throwable $e) {
            LoggerService::exception($e, 'Exception during user registration');
            return $this->renderResponse('auth/register.twig', [
                'error' => 'An unexpected error occurred. Please try again later.',
                'username' => $username,
                'email' => $email,
            ]);
        }
    }
    
    /**
     * Log the user out
     */
    public function logout(Request $request): RedirectResponse
    {
        $userId = null;
        $username = null;
        
        // Get user info before logout for logging
        if (SessionService::isLoggedIn()) {
            $user = SessionService::getCurrentUser();
            if ($user) {
                $userId = $user->getId();
                $username = $user->getUsername();
            }
        }
        
        // Log the user out
        SessionService::logout();
        
        LoggerService::info('User logged out', [
            'user_id' => $userId,
            'username' => $username,
            'ip' => $request->getClientIp()
        ]);
        
        return $this->redirect('/');
    }
} 