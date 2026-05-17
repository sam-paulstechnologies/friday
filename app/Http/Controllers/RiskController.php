<?php

namespace App\Http\Controllers;

use App\Models\Risk;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RiskController extends CommandCenterController
{
    protected function modelClass(): string { return Risk::class; }
    protected function page(): string { return 'Risks/Index'; }
    protected function openStatus(): string { return 'open'; }
    protected function closedStatus(): string { return 'closed'; }
    protected function closedTimestampColumn(): string { return 'closed_at'; }

    protected function extraValidation(): array
    {
        return [
            'impact' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'probability' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'mitigation' => ['nullable', 'string'],
        ];
    }

    public function update(Request $request, Risk $risk)
    {
        return $this->updateItem($request, $risk);
    }

    public function close(Request $request, Risk $risk)
    {
        return $this->closeItem($request, $risk);
    }
}
