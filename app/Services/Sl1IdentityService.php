<?php

namespace App\Services;

use App\Models\InstanceSettings;
use App\Models\PendingIntent;
use App\Models\Sl1IdentityBinding;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class Sl1IdentityService
{
    private const SESSION_KEY = 'sl1_connect_login';

    private const INTENT_SESSION_KEY = 'sl1_intent_signing';

    public function authorizationUrl(Request $request, ?string $claimToken = null): string
    {
        $state = Str::random(40);
        $nonce = Str::random(40);
        $redirectUri = route('auth.sl1.callback');

        $sessionPayload = [
            'state' => $state,
            'nonce' => $nonce,
            'redirect_uri' => $redirectUri,
            'created_at' => now()->toIso8601String(),
        ];

        if ($claimToken) {
            $sessionPayload['claim_token'] = $claimToken;
            $sessionPayload['flow'] = 'admin_claim';
        }

        $request->session()->put(self::SESSION_KEY, $sessionPayload);

        return $this->pushedAuthorizationUrl([
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'nonce' => $nonce,
            'mode' => 'connect',
            'flow' => $claimToken ? 'admin_claim' : 'connect',
            // ADR-0056: request the non-authoritative email contact claim.
            'scope' => 'openid sl1e email',
        ]);
    }

    public function invitationAuthorizationUrl(Request $request, TeamInvitation $invitation): string
    {
        if (! $invitation->isValid()) {
            throw new RuntimeException('Team invitation expired.');
        }
        if (! $invitation->artifact || $invitation->artifact->status !== 'issued') {
            throw new RuntimeException('Team invitation is waiting for owner SL1 signature.');
        }

        $state = Str::random(40);
        $nonce = Str::random(40);
        $redirectUri = route('auth.sl1.callback');

        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'nonce' => $nonce,
            'redirect_uri' => $redirectUri,
            'flow' => 'team_invitation',
            'invitation_uuid' => $invitation->uuid,
            'created_at' => now()->toIso8601String(),
        ]);

        return $this->pushedAuthorizationUrl([
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'nonce' => $nonce,
            'mode' => 'connect',
            'flow' => 'team_invitation',
            'scope' => 'openid sl1e email',
            'intent_type' => 'team.member.join',
            'intent_title' => 'Join team: '.$invitation->team->name,
            'intent_description' => 'Create or use an SL1 Identity to accept the signed team invitation.',
            'intent_cta' => 'Join with SL1 Identity',
        ]);
    }

    public function intentAuthorizationUrl(Request $request, PendingIntent $intent, bool $popup = false): string
    {
        $user = $request->user();
        if (! $user) {
            throw new RuntimeException('SL1 intent signing requires an active Coolify session.');
        }

        if ($intent->status !== PendingIntent::STATUS_PENDING) {
            throw new RuntimeException('This execution intent is no longer pending.');
        }

        if (! $user->teams()->whereKey($intent->team_id)->exists()) {
            throw new RuntimeException('This execution intent belongs to a different team.');
        }

        $binding = $user->sl1IdentityBinding;
        if (! $binding?->entity_address) {
            throw new RuntimeException('Bind this Coolify user to SL1 Identity before signing intents.');
        }

        $state = Str::random(40);
        $nonce = Str::random(40);
        $redirectUri = route('auth.sl1.intent.callback', $popup ? ['popup' => 1] : []);
        $intentHash = $this->canonicalIntentHash($intent);
        $intentResource = $this->intentResource($intent, $intentHash);

        $request->session()->put(self::INTENT_SESSION_KEY, [
            'state' => $state,
            'nonce' => $nonce,
            'redirect_uri' => $redirectUri,
            'intent_id' => $intent->id,
            'intent_uuid' => $intent->uuid,
            'intent_hash' => $intentHash,
            'intent_resource' => $intentResource,
            'user_id' => $user->id,
            'team_id' => $intent->team_id,
            'entity_address' => $binding->entity_address,
            'created_at' => now()->toIso8601String(),
        ]);

        $rule = app(PolicyEngine::class)->getRule($intent->event_type);
        $targetName = (string) (data_get($intent->payload, 'application_name')
            ?: data_get($intent->payload, 'server_name')
            ?: class_basename((string) $intent->target_type).' #'.$intent->target_id);
        $intentAction = str_replace('.', ' > ', $intent->event_type);

        return $this->pushedAuthorizationUrl([
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'nonce' => $nonce,
            'mode' => 'connect',
            'flow' => 'connect',
            'identity_hint' => $binding->entity_address,
            'intent_type' => $intent->event_type,
            'intent_title' => 'Confirm intent: '.$intentAction,
            'intent_description' => sprintf(
                'Sovereign Coolify will release "%s" for "%s" only after your SL1 passkey signs this canonical intent hash.',
                $rule['title'] ?? $intentAction,
                $targetName,
            ),
            'intent_cta' => 'Confirm Intent in Wallet',
            'intent_nonce' => $intentHash,
            'intent_resource' => $intentResource,
        ]);
    }

    /**
     * @return array{identity: array<string, mixed>, proof: array<string, mixed>, exchange: array<string, mixed>, introspection: array<string, mixed>, session: array<string, mixed>}
     *
     * @throws ConnectionException
     */
    public function completeCallback(Request $request): array
    {
        $expected = $request->session()->pull(self::SESSION_KEY);
        if (! is_array($expected)) {
            throw new RuntimeException('SL1 login session expired. Start again.');
        }

        if ($request->query('state') !== ($expected['state'] ?? null)) {
            throw new RuntimeException('SL1 login state mismatch.');
        }

        $verified = $this->exchangeAndIntrospect($request, $expected);
        $proof = $verified['proof'];
        $this->assertProofMatchesSession($proof, $expected);
        app(EmbeddedSl1RuntimeService::class)->recordVerifiedIdentity($verified, 'sl1-connect-callback');

        return $verified;
    }

    public function completeIntentCallback(Request $request): PendingIntent
    {
        $expected = $request->session()->pull(self::INTENT_SESSION_KEY);
        if (! is_array($expected)) {
            throw new RuntimeException('SL1 intent signing session expired. Start again.');
        }

        if ($request->query('state') !== ($expected['state'] ?? null)) {
            throw new RuntimeException('SL1 intent signing state mismatch.');
        }

        $user = $request->user();
        if (! $user || (int) $user->id !== (int) ($expected['user_id'] ?? -1)) {
            throw new RuntimeException('SL1 intent signing requires the same active Coolify user session.');
        }

        $intent = PendingIntent::query()
            ->whereKey($expected['intent_id'] ?? null)
            ->where('uuid', $expected['intent_uuid'] ?? null)
            ->first();
        if (! $intent || $intent->status !== PendingIntent::STATUS_PENDING) {
            throw new RuntimeException('Pending intent not found or already processed.');
        }

        if (! $user->teams()->whereKey($intent->team_id)->exists()) {
            throw new RuntimeException('This execution intent belongs to a different team.');
        }

        $verified = $this->exchangeAndIntrospect($request, $expected);
        $proof = $verified['proof'];
        $identity = $verified['identity'];
        $this->assertProofMatchesSession($proof, $expected);

        if ((string) data_get($proof, 'type') !== 'sl1e.intent.proof.v1') {
            throw new RuntimeException('SL1 proof is not an intent approval proof.');
        }

        $entityAddress = (string) (data_get($proof, 'entity_l1_address') ?: data_get($identity, 'entity_l1_address'));
        if ($entityAddress === '' || $entityAddress !== (string) ($expected['entity_address'] ?? '')) {
            throw new RuntimeException('SL1 signer does not match the current Coolify identity binding.');
        }

        $intentHash = $this->canonicalIntentHash($intent);
        if (! hash_equals((string) ($expected['intent_hash'] ?? ''), $intentHash)) {
            throw new RuntimeException('Pending intent changed before signing completed.');
        }

        $proofIntent = data_get($proof, 'intent', []);
        if (! is_array($proofIntent)
            || (string) data_get($proofIntent, 'type') !== $intent->event_type
            || (string) data_get($proofIntent, 'nonce') !== $intentHash
            || (string) data_get($proofIntent, 'resource') !== $this->intentResource($intent, $intentHash)) {
            throw new RuntimeException('SL1 proof does not match the pending execution intent.');
        }

        app(EmbeddedSl1RuntimeService::class)->recordVerifiedIdentity($verified, 'sl1-intent-signing');
        $user->sl1IdentityBinding?->forceFill($this->bindingAttributes($verified))->save();

        $signed = app(PolicyEngine::class)->addSignature(
            intent: $intent,
            actorDid: 'DID:SL1|ENTITY:#'.$entityAddress,
            role: 'sl1-intent-approval',
            evidence: [
                'proof_id' => data_get($proof, 'proof_id') ?: data_get($proof, 'proofId'),
                'proof_type' => data_get($proof, 'type'),
                'controller_address' => data_get($proof, 'controller_l1_address') ?: data_get($proof, 'keyAddress'),
                'intent_hash' => $intentHash,
                'signature' => data_get($proof, 'signature'),
            ],
        );

        if (! $signed) {
            throw new RuntimeException('This SL1 identity has already signed the pending intent.');
        }

        return $intent->refresh();
    }

    /**
     * @param  array{identity: array<string, mixed>, proof: array<string, mixed>}  $verified
     */
    public function userForVerifiedIdentity(array $verified): User
    {
        $proof = $verified['proof'];
        $identity = $verified['identity'];
        $entityAddress = (string) (data_get($proof, 'entity_l1_address') ?: data_get($identity, 'entity_l1_address'));

        if ($entityAddress === '') {
            throw new RuntimeException('SL1 entity address is missing.');
        }

        $binding = Sl1IdentityBinding::query()->where('entity_address', $entityAddress)->first();
        if ($binding) {
            $this->updateBinding($binding, $verified);

            return $binding->user()->with('teams')->firstOrFail();
        }

        $settings = instanceSettings();
        $firstUser = User::query()->count() === 0;
        if (! $firstUser && ! $settings->is_registration_enabled) {
            throw new RuntimeException('Registration is disabled for new SL1 identities.');
        }

        $user = $this->createUserProjection($verified, $firstUser, $settings);
        $this->createBinding($user, $verified);

        return $user->load('teams');
    }

    /**
     * @param  array{identity: array<string, mixed>, proof: array<string, mixed>}  $verified
     * @return array{user: User, team: Team}
     */
    public function userForVerifiedInvitation(string $invitationUuid, array $verified): array
    {
        return DB::transaction(function () use ($invitationUuid, $verified) {
            $invitation = TeamInvitation::query()
                ->where('uuid', $invitationUuid)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $invitation->isValid()) {
                throw new RuntimeException('Team invitation expired.');
            }

            $artifact = $invitation->artifact;
            if (! $artifact || $artifact->status !== 'issued' || $artifact->expires_at->isPast()) {
                throw new RuntimeException('Team invitation is not active.');
            }

            $proof = $verified['proof'];
            $identity = $verified['identity'];
            $entityAddress = (string) (data_get($proof, 'entity_l1_address') ?: data_get($identity, 'entity_l1_address'));
            if ($entityAddress === '') {
                throw new RuntimeException('SL1 entity address is missing.');
            }

            $binding = Sl1IdentityBinding::query()->where('entity_address', $entityAddress)->first();
            if ($binding) {
                $this->updateBinding($binding, $verified);
                $user = $binding->user()->firstOrFail();
            } else {
                $user = User::query()->where('email', $invitation->email)->first()
                    ?: $this->createInvitedUserProjection($verified, $invitation);
                $this->createBinding($user, $verified);
            }

            if (! $user->teams()->whereKey($invitation->team_id)->exists()) {
                $user->teams()->attach($invitation->team_id, ['role' => $invitation->role]);
            }

            $artifact->forceFill([
                'status' => 'consumed',
                'consumed_at' => now(),
                'consumed_by_user_id' => $user->id,
                'consumed_by_entity_address' => $entityAddress,
            ])->save();
            $team = $invitation->team;
            $invitation->delete();

            return [
                'user' => $user->load('teams'),
                'team' => $team,
            ];
        });
    }

    public function establishCoolifySession(User $user, ?Team $preferredTeam = null): void
    {
        $user->updated_at = now();
        $user->save();

        $currentTeam = $preferredTeam && $user->teams()->whereKey($preferredTeam->id)->exists()
            ? $preferredTeam
            : $user->teams->firstWhere('personal_team', true);
        if (! $currentTeam) {
            $currentTeam = $user->recreate_personal_team();
            $user->load('teams');
        }

        session(['currentTeam' => $currentTeam]);
        session(['sl1_entity_address' => $user->sl1IdentityBinding?->entity_address]);
    }

    private function assertProofMatchesSession(array $proof, array $expected): void
    {
        if ((string) data_get($proof, 'audience') !== $this->clientId()) {
            throw new RuntimeException('SL1 proof audience mismatch.');
        }

        if ((string) data_get($proof, 'nonce') !== (string) $expected['nonce']) {
            throw new RuntimeException('SL1 proof nonce mismatch.');
        }

        $expiresValue = data_get($proof, 'expires_at') ?: data_get($proof, 'expiresAt');
        if (! is_string($expiresValue) || $expiresValue === '') {
            throw new RuntimeException('SL1 proof expiry is missing.');
        }

        $expiresAt = Carbon::parse($expiresValue);
        if ($expiresAt->isPast()) {
            throw new RuntimeException('SL1 proof expired.');
        }
    }

    /**
     * @return array{identity: array<string, mixed>, proof: array<string, mixed>, exchange: array<string, mixed>, introspection: array<string, mixed>, session: array<string, mixed>}
     *
     * @throws ConnectionException
     */
    private function exchangeAndIntrospect(Request $request, array $expected): array
    {
        $code = (string) $request->query('code', '');
        if ($code === '') {
            throw new RuntimeException('SL1 authorization code is missing.');
        }

        $exchange = Http::timeout($this->timeout())
            ->acceptJson()
            ->post($this->issuerUrl('/api/sl1e/authorization-code/exchange'), [
                'code' => $code,
                'client_id' => $this->clientId(),
                'redirect_uri' => $expected['redirect_uri'],
            ]);

        if ($exchange->failed()) {
            throw new RuntimeException('SL1 authorization code exchange failed.');
        }

        $exchangePayload = $exchange->json();
        $proofToken = data_get($exchangePayload, 'proof_token');
        if (! is_string($proofToken) || $proofToken === '') {
            throw new RuntimeException('SL1 proof token is missing.');
        }

        $introspection = Http::timeout($this->timeout())
            ->acceptJson()
            ->post($this->issuerUrl('/api/sl1e/proofs/introspect'), [
                'proof_token' => $proofToken,
                'audience' => $this->clientId(),
            ]);

        if ($introspection->failed()) {
            throw new RuntimeException('SL1 proof introspection failed.');
        }

        $introspectionPayload = $introspection->json();
        $proof = data_get($introspectionPayload, 'proof');
        $identity = data_get($introspectionPayload, 'identity', []);

        if (! is_array($proof)) {
            throw new RuntimeException('SL1 identity proof is missing.');
        }

        return [
            'identity' => is_array($identity) ? $identity : [],
            'proof' => $proof,
            'exchange' => is_array($exchangePayload) ? $exchangePayload : [],
            'introspection' => is_array($introspectionPayload) ? $introspectionPayload : [],
            'session' => $expected,
        ];
    }

    private function canonicalIntentHash(PendingIntent $intent): string
    {
        return hash('sha256', json_encode(
            $this->sortCanonicalValue([
                'protocol' => 'coolify.sovereign.intent.v1',
                'uuid' => $intent->uuid,
                'event_type' => $intent->event_type,
                'target_type' => $intent->target_type,
                'target_id' => $intent->target_id,
                'team_id' => $intent->team_id,
                'payload' => $intent->payload ?? [],
                'created_at' => $intent->created_at?->toIso8601String(),
            ]),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }

    private function intentResource(PendingIntent $intent, string $intentHash): string
    {
        return "coolify:pending_intent:{$intent->uuid}:{$intentHash}";
    }

    private function sortCanonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->sortCanonicalValue($item), $value);
        }

        ksort($value);

        return array_map(fn ($item) => $this->sortCanonicalValue($item), $value);
    }

    /**
     * @param  array{identity: array<string, mixed>, proof: array<string, mixed>}  $verified
     */
    private function createUserProjection(array $verified, bool $firstUser, InstanceSettings $settings): User
    {
        $proof = $verified['proof'];
        $entityAddress = (string) data_get($proof, 'entity_l1_address');
        $displayName = (string) (data_get($proof, 'displayName') ?: data_get($proof, 'display_alias') ?: data_get($proof, 'alias') ?: $entityAddress);
        $email = 'sl1-'.substr(hash('sha256', $entityAddress), 0, 20).'@identity.sl1.local';

        if ($firstUser) {
            $user = (new User)->forceFill([
                'id' => 0,
                'name' => $displayName,
                'email' => $email,
                'password' => null,
                'email_verified_at' => now(),
            ]);
            $user->save();

            $settings->is_registration_enabled = false;
            $settings->save();

            return $user;
        }

        $user = User::create([
            'name' => $displayName,
            'email' => $email,
            'password' => null,
        ]);
        $user->markEmailAsVerified();

        return $user;
    }

    /**
     * @param  array{identity: array<string, mixed>, proof: array<string, mixed>}  $verified
     */
    private function createInvitedUserProjection(array $verified, TeamInvitation $invitation): User
    {
        $proof = $verified['proof'];
        $entityAddress = (string) data_get($proof, 'entity_l1_address');
        $displayName = (string) (data_get($proof, 'displayName') ?: data_get($proof, 'display_alias') ?: data_get($proof, 'alias') ?: $entityAddress);

        $user = User::create([
            'name' => $displayName,
            'email' => $invitation->email,
            'password' => null,
        ]);
        $user->markEmailAsVerified();

        return $user;
    }

    /**
     * @param  array{identity: array<string, mixed>, proof: array<string, mixed>}  $verified
     */
    private function createBinding(User $user, array $verified): Sl1IdentityBinding
    {
        return Sl1IdentityBinding::create([
            'user_id' => $user->id,
            ...$this->bindingAttributes($verified),
        ]);
    }

    /**
     * @param  array{identity: array<string, mixed>, proof: array<string, mixed>}  $verified
     */
    private function updateBinding(Sl1IdentityBinding $binding, array $verified): void
    {
        $binding->forceFill($this->bindingAttributes($verified))->save();
    }

    /**
     * @param  array{identity: array<string, mixed>, proof: array<string, mixed>}  $verified
     * @return array<string, mixed>
     */
    private function bindingAttributes(array $verified): array
    {
        $proof = $verified['proof'];
        $identity = $verified['identity'];

        return [
            'entity_address' => (string) (data_get($proof, 'entity_l1_address') ?: data_get($identity, 'entity_l1_address')),
            'controller_address' => data_get($proof, 'controller_l1_address') ?: data_get($identity, 'key_l1_address'),
            'alias' => data_get($proof, 'alias') ?: data_get($identity, 'alias'),
            'display_alias' => data_get($proof, 'display_alias') ?: data_get($identity, 'display_alias') ?: data_get($proof, 'displayName'),
            // ADR-0056: non-authoritative contact claim, disclosed only when the
            // user consented to the "email" scope. Never used as an identity key.
            'contact_email' => data_get($identity, 'email') ?: data_get($proof, 'claims.email'),
            'contact_email_hash' => data_get($identity, 'email_hash') ?: data_get($proof, 'claims.email_hash'),
            'proof_id' => data_get($proof, 'proof_id') ?: data_get($proof, 'proofId'),
            'last_proof' => $proof,
            'last_verified_at' => now(),
        ];
    }

    private function issuerUrl(string $path): string
    {
        return rtrim((string) config('sovereign.sl1_connect.issuer'), '/').$path;
    }

    /**
     * @param  array<string, scalar|null>  $authorizeBody
     */
    private function pushedAuthorizationUrl(array $authorizeBody): string
    {
        $secret = $this->clientSecret();
        if ($secret === '') {
            throw new RuntimeException('SL1 PAR client secret is not configured.');
        }

        try {
            $response = Http::timeout($this->timeout())
                ->acceptJson()
                ->withToken($secret)
                ->post($this->issuerUrl('/api/sl1e/authorize/requests'), [
                    'client_id' => $this->clientId(),
                    ...$authorizeBody,
                ]);

            if ($response->successful()) {
                $authorizeUrl = data_get($response->json(), 'authorize_url');
                if (is_string($authorizeUrl) && $authorizeUrl !== '') {
                    return $authorizeUrl;
                }
            }
        } catch (ConnectionException $exception) {
            throw new RuntimeException('SL1 authorize request push failed.', previous: $exception);
        }

        throw new RuntimeException('SL1 authorize request push was rejected.');
    }

    /**
     * @param  array<string, scalar|null>  $authorizeBody
     */
    private function registryAuthorizationUrl(array $authorizeBody): string
    {
        $query = array_filter([
            'state' => $authorizeBody['state'] ?? null,
            'nonce' => $authorizeBody['nonce'] ?? null,
            'mode' => $authorizeBody['mode'] ?? null,
            'flow' => $authorizeBody['flow'] ?? null,
            'scope' => $authorizeBody['scope'] ?? null,
            'identity_hint' => $authorizeBody['identity_hint'] ?? null,
            'intent_type' => $authorizeBody['intent_type'] ?? null,
            'intent_title' => $authorizeBody['intent_title'] ?? null,
            'intent_description' => $authorizeBody['intent_description'] ?? null,
            'intent_cta' => $authorizeBody['intent_cta'] ?? null,
            'intent_nonce' => $authorizeBody['intent_nonce'] ?? null,
            'intent_resource' => $authorizeBody['intent_resource'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== '');

        return $this->issuerUrl('/authorize/'.$this->clientId()).'?'.http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
    }

    private function clientId(): string
    {
        return (string) config('sovereign.sl1_connect.client_id', 'coolify.sovereign');
    }

    private function clientSecret(): string
    {
        return (string) config('sovereign.sl1_connect.client_secret', '');
    }

    private function timeout(): int
    {
        return max(1, (int) config('sovereign.sl1_connect.timeout', 10));
    }
}
