<?php

namespace App\Livewire\Agency;

use App\Services\AgencyOperationsService;
use Livewire\Component;

class Index extends Component
{
    public function render(AgencyOperationsService $operations)
    {
        return view('livewire.agency.index', [
            'summary' => $operations->summaryForTeam(currentTeam()->id),
        ]);
    }
}
