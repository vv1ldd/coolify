<?php

namespace App\Http\Controllers;

use App\Services\EmbeddedSl1RuntimeService;

class EmbeddedSl1RuntimeController extends Controller
{
    public function status(EmbeddedSl1RuntimeService $runtime)
    {
        return response()->json($runtime->status());
    }

    public function issuer(EmbeddedSl1RuntimeService $runtime)
    {
        $status = $runtime->status();

        return response()->json([
            'protocol' => 'simple-l1',
            'issuer' => $status['issuer'],
            'runtime' => $status['runtime'],
            'storage' => $status['storage'],
            'authorization_endpoint' => $status['issuer'].'/authorize',
            'status_endpoint' => $status['issuer'].'/status',
            'proof_exchange_endpoint' => $status['issuer'].'/api/sl1e/authorization-code/exchange',
            'proof_introspection_endpoint' => $status['issuer'].'/api/sl1e/proofs/introspect',
            'capabilities' => [
                'durable_identity_store',
                'proof_projection',
                'coolify_backup_scope',
            ],
        ]);
    }
}
