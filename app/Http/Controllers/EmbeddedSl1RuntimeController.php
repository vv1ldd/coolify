<?php

namespace App\Http\Controllers;

use App\Services\EmbeddedSl1RuntimeService;
use App\Services\Sl1NodeIdentityService;
use Illuminate\Http\Request;

class EmbeddedSl1RuntimeController extends Controller
{
    public function status(EmbeddedSl1RuntimeService $runtime)
    {
        return response()->json($runtime->status());
    }

    public function issuer(EmbeddedSl1RuntimeService $runtime, Sl1NodeIdentityService $nodeIdentity)
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
            'node_identity' => $nodeIdentity->issuerDocumentIdentity(),
            'capabilities' => [
                'durable_identity_store',
                'proof_projection',
                'coolify_backup_scope',
                'node_identity_discovery',
            ],
        ]);
    }

    public function events(Request $request, EmbeddedSl1RuntimeService $runtime, Sl1NodeIdentityService $nodeIdentity)
    {
        return response()->json($runtime->eventStream(
            afterId: (int) $request->query('after_id', $request->query('since', 0)),
            limit: (int) $request->query('limit', 100),
            nodeIdentity: $nodeIdentity,
        ));
    }
}
