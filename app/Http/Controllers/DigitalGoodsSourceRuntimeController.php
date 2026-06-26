<?php

namespace App\Http\Controllers;

use App\Services\InfraLedgerService;
use App\Services\DigitalGoodsSourceRuntimeService;

class DigitalGoodsSourceRuntimeController extends Controller
{
    public function status(DigitalGoodsSourceRuntimeService $runtime, InfraLedgerService $ledger)
    {
        $status = $runtime->status();
        $eventType = data_get($status, 'reachable') && ! data_get($status, 'ready')
            ? 'digital_goods_source.protocol.mismatch'
            : 'digital_goods_source.health.checked';

        $ledger->recordSystem($eventType, [
            'service' => data_get($status, 'service'),
            'reachable' => (bool) data_get($status, 'reachable'),
            'ready' => (bool) data_get($status, 'ready'),
            'checked_url' => data_get($status, 'checked_url'),
            'expected_kernel_protocol_version' => data_get($status, 'kernel_protocol_version'),
            'expected_provider_contract_version' => data_get($status, 'provider_contract_version'),
            'remote_kernel_protocol_version' => data_get($status, 'remote.kernel_protocol_version'),
            'remote_provider_contract_version' => data_get($status, 'remote.provider_contract_version'),
            'remote_ledger_head' => data_get($status, 'remote.ledger_head'),
            'remote_ledger_events_count' => data_get($status, 'remote.ledger_events_count'),
        ]);

        return response()->json($status);
    }
}
