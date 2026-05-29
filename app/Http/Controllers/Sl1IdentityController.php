<?php

namespace App\Http\Controllers;

use App\Models\PendingIntent;
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

    public function intentRedirect(PendingIntent $intent, Request $request, Sl1IdentityService $sl1)
    {
        try {
            return redirect()->away($sl1->intentAuthorizationUrl($request, $intent, $request->boolean('popup')));
        } catch (Throwable $e) {
            Log::warning('SL1 intent signing start failed: '.$e->getMessage());

            if ($request->boolean('popup')) {
                return $this->intentPopupResponse(false, $e->getMessage());
            }

            return redirect()
                ->route('infra.ledger.index')
                ->withErrors(['sl1' => $e->getMessage()]);
        }
    }

    public function intentCallback(Request $request, Sl1IdentityService $sl1)
    {
        try {
            $intent = $sl1->completeIntentCallback($request);
            $message = $intent->status === PendingIntent::STATUS_EXECUTED
                ? 'SL1 intent signed. Quorum reached and execution was released.'
                : 'SL1 intent signed. Waiting for remaining approvals.';

            if ($request->boolean('popup')) {
                return $this->intentPopupResponse(true, $message, [
                    'intent_id' => $intent->id,
                    'intent_uuid' => $intent->uuid,
                    'status' => $intent->status,
                ]);
            }

            return redirect()
                ->route('infra.ledger.index')
                ->with('success', $message);
        } catch (Throwable $e) {
            Log::warning('SL1 intent signing failed: '.$e->getMessage());

            if ($request->boolean('popup')) {
                return $this->intentPopupResponse(false, $e->getMessage());
            }

            return redirect()
                ->route('infra.ledger.index')
                ->withErrors(['sl1' => $e->getMessage()]);
        }
    }

    private function intentPopupResponse(bool $ok, string $message, array $extra = [])
    {
        $safeMessage = e($message);
        $payload = json_encode([
            'type' => 'sl1:intent-signature',
            'ok' => $ok,
            'message' => $message,
            ...$extra,
        ], JSON_THROW_ON_ERROR);

        return response()->make(<<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SL1 Intent Signature</title>
    <style>
        body { margin:0; min-height:100vh; display:grid; place-items:center; background:#080808; color:#f4f4f5; font-family:ui-sans-serif,system-ui,sans-serif; }
        main { width:min(420px,calc(100% - 32px)); padding:24px; border:1px solid #2a2a2d; border-radius:18px; background:#101011; text-align:center; }
        h1 { margin:0 0 10px; font-size:24px; }
        p { color:#a1a1aa; line-height:1.45; }
        button, a { display:inline-flex; margin-top:14px; padding:10px 14px; border-radius:10px; background:#f4f4f5; color:#080808; text-decoration:none; font-weight:800; border:0; cursor:pointer; }
    </style>
</head>
<body>
    <main>
        <h1>{$safeMessage}</h1>
        <p>This SL1 popup can be closed. The Coolify window will refresh automatically if it is still open.</p>
        <button onclick="window.close()">Close</button>
    </main>
    <script>
        const payload = {$payload};
        if (window.opener && !window.opener.closed) {
            window.opener.postMessage(payload, window.location.origin);
            setTimeout(() => window.close(), 350);
        }
    </script>
</body>
</html>
HTML, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
