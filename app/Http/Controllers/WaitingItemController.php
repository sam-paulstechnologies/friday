<?php

namespace App\Http\Controllers;

use App\Models\WaitingItem;
use Illuminate\Http\Request;

class WaitingItemController extends CommandCenterController
{
    protected function modelClass(): string { return WaitingItem::class; }
    protected function page(): string { return 'Waiting/Index'; }
    protected function openStatus(): string { return 'open'; }
    protected function closedStatus(): string { return 'closed'; }
    protected function closedTimestampColumn(): string { return 'closed_at'; }

    protected function extraValidation(): array
    {
        return [
            'waiting_on' => ['nullable', 'string', 'max:255'],
            'follow_up_date' => ['nullable', 'date'],
        ];
    }

    public function update(Request $request, WaitingItem $waitingItem)
    {
        return $this->updateItem($request, $waitingItem);
    }

    public function close(Request $request, WaitingItem $waitingItem)
    {
        return $this->closeItem($request, $waitingItem);
    }
}
