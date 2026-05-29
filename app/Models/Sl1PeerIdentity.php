<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sl1PeerIdentity extends Model
{
    public const TRUST_OBSERVED = 'observed';

    public const TRUST_VERIFIED = 'verified';

    public const TRUST_REVOKED = 'revoked';

    protected $fillable = [
        'sl1_peer_node_id',
        'peer_node_id',
        'signature_algorithm',
        'peer_public_key',
        'trust_state',
        'metadata',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function peer(): BelongsTo
    {
        return $this->belongsTo(Sl1PeerNode::class, 'sl1_peer_node_id');
    }
}
