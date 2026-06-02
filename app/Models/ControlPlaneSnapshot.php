<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class ControlPlaneSnapshot extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'control_plane_peer_id',
        'observed_at',
        'servers_summary',
        'dns_steering_readiness',
        'edge_policy_versions',
        'regional_readiness_summary',
        'metadata',
    ];

    protected $casts = [
        'observed_at' => 'datetime',
        'servers_summary' => 'array',
        'dns_steering_readiness' => 'array',
        'edge_policy_versions' => 'array',
        'regional_readiness_summary' => 'array',
        'metadata' => 'array',
    ];

    protected $hidden = [
        'id',
        'control_plane_peer_id',
    ];

    public function peer()
    {
        return $this->belongsTo(ControlPlanePeer::class, 'control_plane_peer_id');
    }
}
