<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sl1PeerObservedEvent extends Model
{
    public const STATUS_CANDIDATE = 'candidate';

    public const STATUS_DRY_RUN_ADMISSIBLE = 'dry_run_admissible';

    public const STATUS_DRY_RUN_REJECTED = 'dry_run_rejected';

    public const STATUS_PROJECTED = 'projected';

    protected $fillable = [
        'sl1_peer_node_id',
        'remote_event_id',
        'remote_event_uuid',
        'remote_event_hash',
        'event_type',
        'entity_address',
        'controller_address',
        'source',
        'remote_payload',
        'remote_envelope',
        'admissibility_status',
        'admissibility_report',
        'admissibility_evaluated_at',
        'occurred_at',
        'observed_at',
    ];

    protected $casts = [
        'remote_payload' => 'array',
        'remote_envelope' => 'array',
        'admissibility_report' => 'array',
        'admissibility_evaluated_at' => 'datetime',
        'occurred_at' => 'datetime',
        'observed_at' => 'datetime',
    ];

    public function peer(): BelongsTo
    {
        return $this->belongsTo(Sl1PeerNode::class, 'sl1_peer_node_id');
    }
}
