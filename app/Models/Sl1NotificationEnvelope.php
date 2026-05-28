<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class Sl1NotificationEnvelope extends Model
{
    public const VERSION = 'sl1.notification.v1';

    protected $fillable = [
        'uuid',
        'envelope_version',
        'notification_type',
        'team_id',
        'team_invitation_artifact_id',
        'recipient_hint',
        'artifact_ref',
        'issuer_entity_address',
        'status',
        'delivery_channels',
        'subject',
        'body',
        'authority_effect',
        'non_authoritative',
        'consumes_artifact',
        'mutates_authority',
        'capabilities_granted',
        'replay_key',
        'expires_at',
        'read_at',
        'dismissed_at',
        'meta',
    ];

    protected $casts = [
        'recipient_hint' => 'array',
        'artifact_ref' => 'array',
        'delivery_channels' => 'array',
        'non_authoritative' => 'boolean',
        'consumes_artifact' => 'boolean',
        'mutates_authority' => 'boolean',
        'capabilities_granted' => 'array',
        'expires_at' => 'datetime',
        'read_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Sl1NotificationEnvelope $envelope) {
            $envelope->uuid ??= (string) Str::uuid();
            $envelope->envelope_version ??= self::VERSION;
            $envelope->status ??= 'pending';
            $envelope->authority_effect = 'none';
            $envelope->non_authoritative = true;
            $envelope->consumes_artifact = false;
            $envelope->mutates_authority = false;
            $envelope->capabilities_granted = [];
        });

        static::saving(function (Sl1NotificationEnvelope $envelope) {
            if (
                $envelope->authority_effect !== 'none'
                || $envelope->non_authoritative !== true
                || $envelope->consumes_artifact !== false
                || $envelope->mutates_authority !== false
                || filled($envelope->capabilities_granted)
            ) {
                throw new LogicException('NotificationEnvelope cannot carry authority semantics.');
            }
        });

        static::updating(function (Sl1NotificationEnvelope $envelope) {
            $immutable = [
                'uuid',
                'envelope_version',
                'notification_type',
                'team_id',
                'team_invitation_artifact_id',
                'recipient_hint',
                'artifact_ref',
                'issuer_entity_address',
                'delivery_channels',
                'subject',
                'body',
                'authority_effect',
                'non_authoritative',
                'consumes_artifact',
                'mutates_authority',
                'capabilities_granted',
                'replay_key',
                'expires_at',
            ];

            foreach ($immutable as $attribute) {
                if ($envelope->isDirty($attribute)) {
                    throw new LogicException('NotificationEnvelope transport scope is immutable.');
                }
            }
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function teamInvitationArtifact(): BelongsTo
    {
        return $this->belongsTo(TeamInvitationArtifact::class);
    }
}
