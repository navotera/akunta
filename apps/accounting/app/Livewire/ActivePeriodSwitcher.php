<?php

namespace App\Livewire;

use App\Support\ActivePeriod;
use Livewire\Component;

class ActivePeriodSwitcher extends Component
{
    public function select(string $periodId): void
    {
        ActivePeriod::set($periodId);
        $this->redirect(request()->header('referer') ?? url()->current(), navigate: false);
    }

    public function clear(): void
    {
        ActivePeriod::set(null);
        $this->redirect(request()->header('referer') ?? url()->current(), navigate: false);
    }

    public function render()
    {
        return view('livewire.active-period-switcher', [
            'active' => ActivePeriod::resolve(),
            'options' => ActivePeriod::options(),
        ]);
    }
}
