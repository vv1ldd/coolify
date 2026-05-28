<?php

namespace App\Services;

use App\Models\InstanceSettings;
use App\Models\Sl1IdentityBinding;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class Sl1IdentityService
{
    private const SESSION_KEY = 'sl1_connect_login';

    public function authorizationUrl(Request $request): string
    {
        $state = Str::random(40);
        $nonce = Str::random(40);
        $redirectUri = route('auth.sl1.callback');

        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'nonce' => $nonce,
            'redirect_uri' => $redirectUri,
            'created_at' => now()->toIso8601String(),
        ]);

        return $this->issuerUrl('/authorize').'?'.http_build_query([
            'client_id' => $this->clientId(),
            'client_name' => config('sovereign.sl1_connect.client_name', 'Sovereign Coolify'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'nonce' => $nonce,
            'mode' => 'connect',
            'flow' => 'connect',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{identity: array<string, mixed>, proof: array<string, mixed>, exchange: array<string, mixed>, introspection: array<string, mixed>}
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

        $this->assertProofMatchesSession($proof, $expected);

        return [
            'identity' => is_array($identity) ? $identity : [],
            'proof' => $proof,
            'exchange' => is_array($exchangePayload) ? $exchangePayload : [],
            'introspection' => is_array($introspectionPayload) ? $introspectionPayload : [],
        ];
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

    public function establishCoolifySession(User $user): void
    {
        $user->updated_at = now();
        $user->save();

        $currentTeam = $user->teams->firstWhere('personal_team', true);
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
            'proof_id' => data_get($proof, 'proof_id') ?: data_get($proof, 'proofId'),
            'last_proof' => $proof,
            'last_verified_at' => now(),
        ];
    }

    private function issuerUrl(string $path): string
    {
        return rtrim((string) config('sovereign.sl1_connect.issuer'), '/').$path;
    }

    private function clientId(): string
    {
        return (string) config('sovereign.sl1_connect.client_id', 'coolify.sovereign');
    }

    private function timeout(): int
    {
        return max(1, (int) config('sovereign.sl1_connect.timeout', 10));
    }
}
