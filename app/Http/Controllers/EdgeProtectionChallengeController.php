<?php

namespace App\Http\Controllers;

use App\Services\EdgeProtection\EdgeProtectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EdgeProtectionChallengeController extends Controller
{
    public function show(Request $request, EdgeProtectionService $edge): View
    {
        $returnTo = $edge->safeReturnTo($request->query('return_to'));

        return view('edge-protection.challenge', [
            'returnTo' => $returnTo,
            'verifyPath' => $edge->verifyPath(),
            'token' => $edge->createChallengeToken($request, $returnTo),
        ]);
    }

    public function verifyChallenge(Request $request, EdgeProtectionService $edge): RedirectResponse
    {
        $token = $request->query('token');
        $payload = $edge->verifyChallengeToken($request, is_string($token) ? $token : null);
        if ($payload === null) {
            abort(403);
        }

        $response = redirect()->to($edge->safeReturnTo(data_get($payload, 'return_to')));
        $edge->queueProofCookie($response, $request);

        return $response;
    }
}
