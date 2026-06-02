<?php

namespace App\Http\Middleware;

use App\Services\EdgeProtection\EdgeProtectionService;
use App\Services\EdgeProtection\EdgeRequestClassifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EdgeProtectionChallenge
{
    public function __construct(
        private readonly EdgeRequestClassifier $classifier,
        private readonly EdgeProtectionService $edge,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $decision = $this->classifier->classify($request);

        if ($decision->allows()) {
            return $next($request);
        }

        $this->edge->recordDecision($decision, $request);

        if ($decision->blocks()) {
            return response('', $decision->status);
        }

        return redirect()->to($this->edge->challengeUrl($request));
    }
}
