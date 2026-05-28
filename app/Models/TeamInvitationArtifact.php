<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class TeamInvitationArtifact extends Model
{
    protected $fillable = [
        'uuid',
        'artifact_version',
        'team_id',
        'policy_decision_id',
        'issued_by_user_id',
        'issued_by_entity_address',
        'role_scope',
        'invited_principal_hint',
        'delivery_email',
        'delivery_email_hash',
        'replay_key',
        'status',
        'expires_at',
        'consumed_at',
        'consumed_by_user_id',
        'consumed_by_entity_address',
        'revoked_at',
        'meta',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'revoked_at' => 'datetime',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (TeamInvitationArtifact $artifact) {
            $artifact->uuid ??= (string) Str::uuid();
            $artifact->artifact_version ??= 'team.invitation.v1';
            $artifact->status ??= 'issued';
        });

        static::updating(function (TeamInvitationArtifact $artifact) {
            $immutable = [
                'artifact_version',
                'team_id',
                'policy_decision_id',
                'issued_by_user_id',
                'issued_by_entity_address',
                'role_scope',
                'invited_principal_hint',
                'delivery_email',
                'delivery_email_hash',
                'replay_key',
                'expires_at',
            ];

            foreach ($immutable as $attribute) {
                if ($artifact->isDirty($attribute)) {
                    throw new LogicException('TeamInvitationArtifact authority scope is immutable.');
                }
            }
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function policyDecision(): BelongsTo
    {
        return $this->belongsTo(PolicyDecision::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }
}
