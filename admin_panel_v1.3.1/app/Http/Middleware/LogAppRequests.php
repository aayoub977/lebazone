<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Local testing aid: when storage/logs/app-requests.on exists, every API call from
 * the apps is written to storage/logs/app-requests.log with its status and time.
 */
class LogAppRequests
{
    public function handle(Request $request, Closure $next)
    {
        $flag = storage_path('logs/app-requests.on');
        if (!is_file($flag)) {
            return $next($request);
        }
        $t = microtime(true);
        $line = date('H:i:s') . ' ' . $request->method() . ' /' . ltrim($request->path(), '/');
        try {
            $res = $next($request);
            $line .= ' -> ' . $res->getStatusCode();
            return $res;
        } catch (\Throwable $e) {
            $line .= ' -> EXCEPTION ' . $e->getMessage();
            throw $e;
        } finally {
            $line .= ' (' . round((microtime(true) - $t) * 1000) . ' ms) ua=' . substr((string) $request->userAgent(), 0, 40);
            @file_put_contents(storage_path('logs/app-requests.log'), $line . "\n", FILE_APPEND);
        }
    }
}
