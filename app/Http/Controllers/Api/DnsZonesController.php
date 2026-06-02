<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Services\Dns\CloudflareDnsException;
use App\Services\Dns\DnsZoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DnsZonesController extends Controller
{
    public function __construct(private readonly DnsZoneService $dnsZones) {}

    public function index(): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $zones = DnsZone::whereTeamId($teamId)
            ->withCount('records')
            ->orderBy('name')
            ->get()
            ->map(fn (DnsZone $zone) => $this->serializeZone($zone));

        return response()->json($zones);
    }

    public function store(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $body = $this->validatedBody($request, [
            'provider' => 'required|string|in:cloudflare',
            'name' => 'required|string|max:253',
            'provider_zone_id' => 'nullable|string|max:255',
            'api_token' => 'required|string',
        ], ['provider', 'name', 'provider_zone_id', 'api_token']);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        try {
            $zone = $this->dnsZones->createZone($teamId, $body);
        } catch (CloudflareDnsException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        return response()->json($this->serializeZone($zone), 201);
    }

    public function show(Request $request): JsonResponse
    {
        $zone = $this->zoneForCurrentTeam($request->route('uuid'));
        if (! $zone) {
            return response()->json(['message' => 'DNS zone not found.'], 404);
        }

        return response()->json($this->serializeZone($zone->loadCount('records')));
    }

    public function update(Request $request): JsonResponse
    {
        $zone = $this->zoneForCurrentTeam($request->route('uuid'));
        if (! $zone) {
            return response()->json(['message' => 'DNS zone not found.'], 404);
        }

        $body = $this->validatedBody($request, [
            'name' => 'sometimes|required|string|max:253',
            'provider_zone_id' => 'sometimes|nullable|string|max:255',
            'api_token' => 'sometimes|required|string',
        ], ['name', 'provider_zone_id', 'api_token']);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $zone = $this->dnsZones->updateZone($zone, $body);

        return response()->json($this->serializeZone($zone->loadCount('records')));
    }

    public function destroy(Request $request): JsonResponse
    {
        $zone = $this->zoneForCurrentTeam($request->route('uuid'));
        if (! $zone) {
            return response()->json(['message' => 'DNS zone not found.'], 404);
        }

        $zone->delete();

        return response()->json(['message' => 'DNS zone deleted.']);
    }

    public function providerZones(Request $request): JsonResponse
    {
        $zone = $this->zoneForCurrentTeam($request->route('uuid'));
        if (! $zone) {
            return response()->json(['message' => 'DNS zone not found.'], 404);
        }

        try {
            return response()->json($this->dnsZones->listProviderZones($zone));
        } catch (CloudflareDnsException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function records(Request $request): JsonResponse
    {
        $zone = $this->zoneForCurrentTeam($request->route('uuid'));
        if (! $zone) {
            return response()->json(['message' => 'DNS zone not found.'], 404);
        }

        $records = $zone->records()
            ->with('application')
            ->orderBy('name')
            ->orderBy('type')
            ->get()
            ->map(fn (DnsRecord $record) => $this->serializeRecord($record));

        return response()->json($records);
    }

    public function providerRecords(Request $request): JsonResponse
    {
        $zone = $this->zoneForCurrentTeam($request->route('uuid'));
        if (! $zone) {
            return response()->json(['message' => 'DNS zone not found.'], 404);
        }

        try {
            $records = $this->dnsZones->listProviderRecords(
                zone: $zone,
                type: $request->query('type'),
                name: $request->query('name'),
            );
        } catch (CloudflareDnsException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        } catch (ValidationException $e) {
            throw $e;
        }

        return response()->json($records);
    }

    public function upsertRecord(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $zone = $this->zoneForCurrentTeam($request->route('uuid'));
        if (! $zone) {
            return response()->json(['message' => 'DNS zone not found.'], 404);
        }

        $body = $this->validatedBody($request, [
            'type' => 'required|string|in:A,AAAA,CNAME,TXT',
            'name' => 'required|string|max:253',
            'content' => 'required|string|max:4096',
            'ttl' => 'nullable|integer|min:1|max:86400',
            'proxied' => 'nullable|boolean',
            'application_uuid' => 'nullable|string',
            'comment' => 'nullable|string|max:512',
        ], ['type', 'name', 'content', 'ttl', 'proxied', 'application_uuid', 'comment']);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        try {
            $record = $this->dnsZones->upsertManagedRecord($zone, $body, $teamId);
        } catch (CloudflareDnsException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        return response()->json($this->serializeRecord($record->load('application')), 201);
    }

    public function destroyRecord(Request $request): JsonResponse
    {
        $zone = $this->zoneForCurrentTeam($request->route('uuid'));
        if (! $zone) {
            return response()->json(['message' => 'DNS zone not found.'], 404);
        }

        $record = $zone->records()->whereUuid($request->route('record_uuid'))->first();
        if (! $record) {
            return response()->json(['message' => 'DNS record not found.'], 404);
        }

        try {
            $this->dnsZones->deleteManagedRecord($record);
        } catch (CloudflareDnsException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        return response()->json(['message' => 'DNS record deleted.']);
    }

    private function zoneForCurrentTeam(?string $uuid): ?DnsZone
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId) || blank($uuid)) {
            return null;
        }

        return DnsZone::whereTeamId($teamId)->whereUuid($uuid)->first();
    }

    private function validatedBody(Request $request, array $rules, array $allowedFields): array|JsonResponse
    {
        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $body = $request->json()->all();
        $validator = customApiValidator($body, $rules);
        $extraFields = array_diff(array_keys($body), $allowedFields);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        return $body;
    }

    private function serializeZone(DnsZone $zone): array
    {
        return [
            'uuid' => $zone->uuid,
            'name' => $zone->name,
            'provider' => $zone->provider,
            'provider_zone_id' => $zone->provider_zone_id,
            'records_count' => $zone->records_count,
            'created_at' => $zone->created_at,
            'updated_at' => $zone->updated_at,
        ];
    }

    private function serializeRecord(DnsRecord $record): array
    {
        return [
            'uuid' => $record->uuid,
            'type' => $record->type,
            'name' => $record->name,
            'content' => $record->content,
            'ttl' => $record->ttl,
            'proxied' => $record->proxied,
            'comment' => $record->comment,
            'application_uuid' => $record->application?->uuid,
            'provider_record_id' => $record->provider_record_id,
            'metadata' => $record->metadata,
            'created_at' => $record->created_at,
            'updated_at' => $record->updated_at,
        ];
    }
}
