<?php

namespace App\Livewire\RealmOperations;

use App\Services\Realm\RealmOperationsService;
use Livewire\Component;

/**
 * Human-facing projection of Realm evidence.
 *
 * This component displays evidence.
 * It does not define protocol state.
 *
 * See ADR-0012.
 */
class Index extends Component
{
    public function render(RealmOperationsService $operations)
    {
        return view('livewire.realm-operations.index', [
            'snapshot' => $operations->snapshot(currentTeam()?->id),
        ])->title('Realm Operations | Sovereign');
    }
}
