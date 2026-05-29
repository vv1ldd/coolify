<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sl1PeerSyncCursor extends Model
{
    public const TYPE_IDENTITY_EVENTS = 'identity_events';

    public const STATUS_IDLE = 'idle';

    public const STATUS_SYNCED = 'synced';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'sl1_peer_node_id',
        'cursor_type',
        'remote_cursor',
        'status',
        'last_error',
        'last_synced_at',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];

    public function peer(): BelongsTo
    {
        return $this->belongsTo(Sl1PeerNode::class, 'sl1_peer_node_id');
    }
}
