<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends people to HTTPS when APP_URL is an https:// address. The terminal's /iclock
 * endpoints are not part of the web routes, so terminals that only speak HTTP still work.
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->secure() && str_starts_with((string) config('app.url'), 'https://')) {
            // Built from the raw URL so a sub-folder such as /attendance isn't added twice
            return redirect()->away('https://'.$request->getHttpHost().$request->getRequestUri(), 301);
        }

        return $next($request);
    }
}
