<?php

namespace App\Http\Controllers;

use App\Services\Sl1IdentityService;
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

    public function callback(Request $request, Sl1IdentityService $sl1)
    {
        try {
            $verified = $sl1->completeCallback($request);
            $user = $sl1->userForVerifiedIdentity($verified);

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
