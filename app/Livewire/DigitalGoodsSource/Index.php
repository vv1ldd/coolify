<?php

namespace App\Livewire\DigitalGoodsSource;

use App\Services\DigitalGoodsSourceRuntimeService;
use Livewire\Component;

class Index extends Component
{
    public function render(DigitalGoodsSourceRuntimeService $runtime)
    {
        return view('livewire.digital-goods-source.index', [
            'status' => $runtime->status(),
        ]);
    }
}
