<?php

namespace App\Http\Controllers;

use App\Services\Sl1IdentityService;
use App\Services\SovereignAdminClaimService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class Sl1IdentityController extends Controller
{
    public function redirect(Request $request, Sl1IdentityService $sl1)
    {
        return redirect()->away($sl1->authorizationUrl($request));
    }

    public function adminClaim(string $token, Request $request, Sl1IdentityService $sl1, SovereignAdminClaimService $claims)
    {
        try {
            $claims->assertPendingToken($token);

            return redirect()->away($sl1->authorizationUrl($request, $token));
        } catch (Throwable $e) {
            Log::warning('SL1 admin claim start failed: '.$e->getMessage());

            return redirect()
                ->route('login')
                ->withErrors(['sl1' => $e->getMessage()]);
        }
    }

    public function callback(Request $request, Sl1IdentityService $sl1, SovereignAdminClaimService $claims)
    {
        try {
            $verified = $sl1->completeCallback($request);
            $claimToken = data_get($verified, 'session.claim_token');
            $user = is_string($claimToken) && $claimToken !== ''
                ? $claims->claim($claimToken, $verified)
                : $sl1->userForVerifiedIdentity($verified);

            Auth::login($user);
            $sl1->establishCoolifySession($user);

            return redirect('/');
        } catch (Throwable $e) {
            Log::warning('SL1 identity login failed: '.$e->getMessage());

            return redirect()
                ->route('login')
                ->withErrors(['sl1' => $e->getMessage()]);
        }
    }
}
