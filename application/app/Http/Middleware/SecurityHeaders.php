<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();
        $response = $next($request);
        $nonce = Vite::cspNonce();
        $dev = app()->environment('local') && Vite::isRunningHot();
        $scripts = $dev ? " 'unsafe-eval' http://localhost:5173 http://127.0.0.1:5173" : '';
        $connect = $dev ? ' http://localhost:5173 http://127.0.0.1:5173 ws://localhost:5173 ws://127.0.0.1:5173' : '';
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self' 'nonce-$nonce'$scripts; style-src 'self'".($dev ? " 'unsafe-inline'" : '')."; img-src 'self' data:; font-src 'self'; connect-src 'self'$connect; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; object-src 'none'");
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
