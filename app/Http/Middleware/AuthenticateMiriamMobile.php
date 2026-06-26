<?php

namespace App\Http\Middleware;

use App\Models\MiriamMobileToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMiriamMobile
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = (string) $request->bearerToken();

        if ($plainToken === '') {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token = MiriamMobileToken::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        if (! $token || ! $token->user || ($token->expires_at && $token->expires_at->isPast())) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('miriam_mobile_token', $token);

        return $next($request);
    }
}
