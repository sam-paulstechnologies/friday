<?php

namespace App\Http\Controllers;

use App\Services\OperationsCenter\OperationsCenterGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OperationsCenterController extends Controller
{
    public function index(Request $request, OperationsCenterGraphService $graphs): Response
    {
        return Inertia::render('OperationsCenter/Index', [
            'initialView' => 'journey-flow',
            'initialGraph' => $graphs->journeyFlow($request->user()),
            'tabs' => [
                ['key' => 'journey-flow', 'label' => 'Journey Flow'],
                ['key' => 'mind-map', 'label' => 'Mind Map'],
                ['key' => 'technical-map', 'label' => 'Technical Map'],
            ],
            'endpoints' => [
                'graph' => route('operations-center.graph', ['view' => '__VIEW__'], false),
                'details' => route('operations-center.nodes.show', ['view' => '__VIEW__', 'node' => '__NODE__'], false),
            ],
            'permissions' => [
                'technical_map' => $graphs->canViewTechnicalMap($request->user()),
            ],
        ]);
    }

    public function graph(Request $request, OperationsCenterGraphService $graphs, string $view): JsonResponse
    {
        $this->validateView($view);

        return response()->json($graphs->graph($request->user(), $view, $this->expandedBranches($request)));
    }

    public function node(Request $request, OperationsCenterGraphService $graphs, string $view, string $node): JsonResponse
    {
        $this->validateView($view);

        return response()->json($graphs->nodeDetails($request->user(), $view, $node));
    }

    private function validateView(string $view): void
    {
        validator(
            ['view' => $view],
            ['view' => ['required', 'string', Rule::in(['journey-flow', 'mind-map', 'technical-map', 'agent-orchestrator'])]],
        )->validate();
    }

    private function expandedBranches(Request $request): array
    {
        $expanded = $request->query('expanded', []);

        if (is_string($expanded)) {
            $expanded = array_filter(explode(',', $expanded));
        }

        return collect($expanded)
            ->filter(fn ($value) => is_string($value) && strlen($value) <= 80)
            ->map(fn ($value) => trim($value))
            ->unique()
            ->values()
            ->all();
    }
}
