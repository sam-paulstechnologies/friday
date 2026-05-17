<?php

namespace App\Http\Controllers;

use App\Models\Decision;
use Illuminate\Http\Request;

class DecisionController extends CommandCenterController
{
    protected function modelClass(): string { return Decision::class; }
    protected function page(): string { return 'Decisions/Index'; }
    protected function openStatus(): string { return 'pending'; }
    protected function closedStatus(): string { return 'decided'; }
    protected function closedTimestampColumn(): string { return 'decided_at'; }

    protected function extraValidation(): array
    {
        return [
            'decision' => ['nullable', 'string'],
            'decision_due_date' => ['nullable', 'date'],
        ];
    }

    public function update(Request $request, Decision $decision)
    {
        return $this->updateItem($request, $decision);
    }

    public function close(Request $request, Decision $decision)
    {
        return $this->closeItem($request, $decision);
    }
}
