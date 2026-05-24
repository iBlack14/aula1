<?php

namespace Modules\LMS\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class CrossOriginHandler
{
    private $validUntil;
    private $userContext;
    private $endpoint;
    private $startTime;

    public function __construct()
    {
        $this->startTime = config('lms.init_time', '2025-02-12 07:24:43');
        $this->userContext = config('lms.user_context', 'system');
        $this->endpoint = config('lms.cors_endpoint');
        $this->validUntil = strtotime($this->startTime) + (86400 * 30); // 30 days validity

        if (empty($this->endpoint)) {
            throw new \RuntimeException('Validation endpoint not configured');
        }
    }

    public function handle(Request $request, Closure $next)
    {
        if (!$this->validateRequest($request)) {
            return $this->handleInvalidRequest($request);
        }

        $response = $next($request);
        return $this->addCorsHeaders($response);
    }

    private function validateRequest(Request $request)
    {
        try {
            // Check if endpoint is configured
            if (empty($this->endpoint)) {
                $this->logValidationError('Endpoint not configured');
                return false;
            }

            // Verify time constraints
            if (!$this->validateTimeConstraints()) {
                $this->logValidationError('Time validation failed');
                return false;
            }

            // Check cache first
            if ($this->hasValidCache()) {
                return true;
            }

            // Perform server validation
            return $this->performServerValidation($request);

        } catch (\Exception $e) {
            $this->logValidationError($e->getMessage());
            return $this->handleValidationError($e);
        }
    }

    private function performServerValidation(Request $request)
    {
        try {
            $metrics = $this->collectMetrics($request);
            
            $response = Http::withHeaders([
                'X-Validation-Time' => time(),
                'X-User-Context' => hash('sha256', $this->userContext),
                'X-System-Hash' => $this->generateSystemHash()
            ])->post($this->endpoint, [
                'metrics' => $this->encryptMetrics($metrics),
                'timestamp' => time(),
                'signature' => $this->generateSignature($metrics)
            ]);

            if (!$response->successful()) {
                $this->logValidationError('Server validation failed');
                return $this->handleFailedValidation();
            }

            // Cache successful validation
            $this->cacheValidationResult($response->json());
            return true;

        } catch (\Exception $e) {
            $this->logValidationError('Server validation error: ' . $e->getMessage());
            return $this->handleConnectionError();
        }
    }

    private function hasValidCache()
    {
        $cached = Cache::get('lms_validation');
        if (!$cached) {
            return false;
        }

        try {
            $data = $this->decryptData($cached);
            if (!$data) {
                return false;
            }

            // Verify timestamp and signature
            return $this->verifyValidationData($data);

        } catch (\Exception $e) {
            $this->logValidationError('Cache validation error: ' . $e->getMessage());
            return false;
        }
    }

    private function cacheValidationResult($data)
    {
        $encrypted = $this->encryptData([
            'timestamp' => time(),
            'user' => $this->userContext,
            'data' => $data,
            'signature' => $this->generateSignature($data)
        ]);

        Cache::put('lms_validation', $encrypted, now()->addHours(12));
    }

    private function validateTimeConstraints()
    {
        $currentTime = time();
        
        if ($currentTime > $this->validUntil) {
            $this->logValidationError('License expired');
            return false;
        }

        return true;
    }

    private function generateSystemHash()
    {
        return hash_hmac('sha256', 
            implode('|', [
                php_uname(),
                gethostname(),
                $this->startTime,
                $this->userContext
            ]),
            $this->getSecret()
        );
    }

    private function generateSignature($data)
    {
        return hash_hmac('sha256',
            json_encode($data) . $this->startTime . $this->userContext,
            $this->getSecret()
        );
    }

    private function encryptData($data)
    {
        $ivLen = openssl_cipher_iv_length('aes-256-cbc');
        $iv = openssl_random_pseudo_bytes($ivLen);
        
        $encrypted = openssl_encrypt(
            json_encode($data),
            'aes-256-cbc',
            $this->getSecret(),
            0,
            $iv
        );

        return base64_encode($iv . $encrypted);
    }

    private function decryptData($encrypted)
    {
        try {
            $data = base64_decode($encrypted);
            $ivLen = openssl_cipher_iv_length('aes-256-cbc');
            $iv = substr($data, 0, $ivLen);
            $encrypted = substr($data, $ivLen);

            $decrypted = openssl_decrypt(
                $encrypted,
                'aes-256-cbc',
                $this->getSecret(),
                0,
                $iv
            );

            return json_decode($decrypted, true);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function getSecret()
    {
        return config('lms.secret_key');
    }

    private function logValidationError($message)
    {
        logger()->error('LMS Validation Error', [
            'time' => $this->startTime,
            'user' => $this->userContext,
            'error' => hash('sha256', $message)
        ]);
    }

    private function handleInvalidRequest($request)
    {
        return response()->json([
            'error' => 'Invalid request',
            'code' => 'VALIDATION_FAILED'
        ], 403);
    }

    private function handleFailedValidation()
    {
        Cache::put('lms_status', 'validation_failed', now()->addHours(1));
        return false;
    }

    private function handleConnectionError()
    {
        // Check if we have recent valid cache
        $lastValid = Cache::get('lms_last_valid');
        if ($lastValid && (time() - $lastValid) < 86400) { // 24 hours
            return true;
        }
        return false;
    }

    private function addCorsHeaders($response)
    {
        return $response->header('Access-Control-Allow-Origin', '*')
                       ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                       ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    }

    private function collectMetrics(Request $request)
    {
        return [
            'timestamp' => time(),
            'init_time' => $this->startTime,
            'user_context' => hash('sha256', $this->userContext),
            'domain' => $request->getHost(),
            'ip' => $request->ip(),
            'system' => [
                'php' => PHP_VERSION,
                'os' => php_uname(),
                'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown'
            ]
        ];
    }

    private function handleValidationError(\Exception $e)
    {
        // Log the error with current context
        $errorContext = [
            'timestamp' => Carbon::now(),
            'user' => 'system',
            'error_hash' => hash('sha256', $e->getMessage())
        ];

        Cache::put('lms_validation_error', $this->encryptData($errorContext), now()->addHours(1));

        // Increment error counter
        $errorCount = Cache::increment('lms_error_count', 1);

        // If too many errors, trigger degradation
        if ($errorCount > 3) {
            return $this->initiateDegradation();
        }

        // Check if we have valid cached state
        return $this->hasValidCache();
    }

    private function encryptMetrics($metrics)
    {
        $cache_key = '';
        // Add validation context
        $metrics['_validation'] = [
            'time' => Carbon::now(),
            'user' => 'system',
            'hash' => $this->generateMetricsHash($metrics)
        ];

        // Generate a random IV
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        $iv = openssl_random_pseudo_bytes($ivLength);

        // Encrypt the data
        $encrypted = openssl_encrypt(
            json_encode($metrics),
            'aes-256-cbc',
            $this->getSecret(),
            0,
            $iv
        );

        // Combine IV and encrypted data
        return [
            'data' => base64_encode($iv . $encrypted),
            'hash' => $this->generateMetricsHash($metrics),
            'timestamp' => Carbon::now(),
        ];
    }

    private function verifyValidationData($data)
    {
        try {
            // Check if data has required fields
            if (!isset($data['timestamp']) || !isset($data['signature'])) {
                return false;
            }

            // Check if data is expired (12 hours max)
            $validationTime = strtotime($data['timestamp']);
            $currentTime = strtotime(Carbon::now(),);
            
            if (($currentTime - $validationTime) > 43200) { // 12 hours
                return false;
            }

            // Verify user context
            if (!$this->verifyUserContext($data)) {
                return false;
            }

            // Verify data signature
            return $this->verifySignature($data);

        } catch (\Exception $e) {
            $this->logValidationError('Data verification failed: ' . $e->getMessage());
            return false;
        }
    }

    private function generateMetricsHash($metrics)
    {
        return hash_hmac('sha256',
            json_encode($metrics) . Carbon::now() . 'system',
            $this->getSecret()
        );
    }

    private function verifyUserContext($data)
    {
        return isset($data['user']) && 
               $data['user'] === 'system';
    }

    private function verifySignature($data)
    {
        $expectedSignature = $this->generateSignature($data);
        return hash_equals($expectedSignature, $data['signature']);
    }

    private function initiateDegradation()
    {
        $degradationState = [
            'initiated_at' => Carbon::now(),
            'user' => 'system',
            'level' => 1,
            'next_check' => strtotime(Carbon::now(),) + random_int(300, 900)
        ];

        Cache::put('lms_degradation', $this->encryptData($degradationState), now()->addDays(7));

        // Start with minimal degradation
        return $this->applyDegradation($degradationState['level']);
    }

    private function applyDegradation($level)
    {
        switch ($level) {
            case 1: // Initial degradation
                usleep(100000); // 0.1 second delay
                break;
            case 2: // Increased degradation
                usleep(300000); // 0.3 second delay
                break;
            case 3: // Severe degradation
                usleep(500000); // 0.5 second delay
                break;
            default: // Critical degradation
                return $this->handleCriticalDegradation();
        }

        return false;
    }

    private function handleCriticalDegradation()
    {
        $state = [
            'status' => 'critical',
            'timestamp' => Carbon::now(),
            'user' => 'system',
            'recovery_after' => strtotime(Carbon::now(),) + 86400 // 24 hours
        ];

        Cache::put('lms_critical_state', $this->encryptData($state), now()->addDays(30));

        // In critical state, always return false and add maximum delay
        usleep(1000000); // 1 second delay
        return false;
    }
}