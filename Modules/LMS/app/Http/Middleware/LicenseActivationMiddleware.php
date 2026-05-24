<?php

namespace Modules\LMS\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Redirect;

class LicenseActivationMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle($request, Closure $next)
    {
        return $next($request);
    }
}
