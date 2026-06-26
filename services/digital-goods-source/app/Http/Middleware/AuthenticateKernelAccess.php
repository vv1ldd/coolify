<?php

namespace App\Http\Middleware;

use App\Models\KernelPartner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateKernelAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $providedToken = trim((string) ($request->header('X-Auth-Token') ?: $request->bearerToken()));
        if ($providedToken === '') {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $platformToken = config('digital-goods-source.platform_token');
        if (is_string($platformToken) && $platformToken !== '' && hash_equals($platformToken, $providedToken)) {
            $request->attributes->set('kernel_auth_scope', 'platform');

            return $next($request);
        }

        $clientId = trim((string) $request->header('X-Client-Id'));
        $partner = KernelPartner::query()
            ->where('is_active', true)
            ->when($clientId !== '', fn ($query) => $query->where('external_id', $clientId))
            ->get()
            ->first(fn (KernelPartner $partner): bool => is_string($partner->api_token) && hash_equals($partner->api_token, $providedToken));

        if (! $partner) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $request->attributes->set('kernel_auth_scope', 'partner');
        $request->attributes->set('kernel_partner', $partner);

        return $next($request);
    }
}
