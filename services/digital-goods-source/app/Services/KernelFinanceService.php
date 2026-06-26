<?php

namespace App\Services;

use App\Models\CreditReservation;
use App\Models\KernelPartner;
use Illuminate\Support\Facades\DB;

class KernelFinanceService
{
    public function partnerBalanceCheck(?KernelPartner $partner, float $requiredUsd): ?array
    {
        if (! $partner) {
            return null;
        }

        $availableUsd = $this->toUsd((float) $partner->available_balance, $partner->currency);

        return [
            'affordable' => $availableUsd >= $requiredUsd,
            'required_usd' => round($requiredUsd, 2),
            'available_usd' => round($availableUsd, 2),
            'balance_currency' => $partner->currency,
        ];
    }

    public function grantCredit(KernelPartner $partner, float $amount, string $reference, array $metadata = []): array
    {
        return DB::transaction(function () use ($partner, $amount, $reference, $metadata): array {
            $existing = CreditReservation::query()
                ->where('kernel_partner_id', $partner->id)
                ->where('reference', $reference)
                ->first();

            if ($existing) {
                return [
                    'success' => true,
                    'idempotent' => true,
                    'reservation_id' => $existing->id,
                    'balance' => (float) $partner->fresh()->available_balance,
                ];
            }

            $reservation = CreditReservation::create([
                'kernel_partner_id' => $partner->id,
                'reference' => $reference,
                'amount' => $amount,
                'currency' => $partner->currency,
                'status' => 'granted',
                'metadata' => $metadata,
            ]);

            $partner->increment('available_balance', $amount);

            return [
                'success' => true,
                'idempotent' => false,
                'reservation_id' => $reservation->id,
                'balance' => (float) $partner->fresh()->available_balance,
            ];
        });
    }

    public function topUp(KernelPartner $partner, float $amount, ?string $reference = null): array
    {
        $partner->increment('available_balance', $amount);

        return [
            'success' => true,
            'reference' => $reference,
            'balance' => (float) $partner->fresh()->available_balance,
            'currency' => $partner->currency,
        ];
    }

    public function debitForOrder(KernelPartner $partner, float $amount): void
    {
        $partner->decrement('available_balance', $amount);
    }

    private function toUsd(float $amount, string $currency): float
    {
        return match (strtoupper($currency)) {
            'USD' => $amount,
            'RUB' => $amount / (float) env('WILDFLOW_RUB_PER_USD', 100),
            default => $amount,
        };
    }
}
