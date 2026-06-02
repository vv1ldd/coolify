<?php

namespace App\Livewire\Domains;

use App\Services\DomainInventoryService;
use Illuminate\Support\Collection;
use Livewire\Component;

class Index extends Component
{
    public string $search = '';

    public function render()
    {
        return view('livewire.domains.index', [
            'entries' => $this->entries(),
        ]);
    }

    private function entries(): Collection
    {
        return app(DomainInventoryService::class)->forTeam(currentTeam()->id, $this->search);
    }
}
