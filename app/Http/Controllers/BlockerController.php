<?php

namespace App\Http\Controllers;

use App\Models\Blocker;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BlockerController extends CommandCenterController
{
    protected function modelClass(): string { return Blocker::class; }
    protected function page(): string { return 'Blockers/Index'; }
    protected function openStatus(): string { return 'open'; }
    protected function closedStatus(): string { return 'resolved'; }
    protected function closedTimestampColumn(): string { return 'resolved_at'; }

    protected function extraValidation(): array
    {
        return [
            'severity' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
        ];
    }

    public function update(Request $request, Blocker $blocker)
    {
        return $this->updateItem($request, $blocker);
    }

    public function close(Request $request, Blocker $blocker)
    {
        return $this->closeItem($request, $blocker);
    }
}
