<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\System\SystemHealthService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SystemHealthController extends Controller
{
    public function __invoke(Request $request, SystemHealthService $healthService): Response
    {
        $workspaceIds = $request->user()->accessibleWorkspaceIds();
        $canManageAnyWorkspace = collect($workspaceIds)
            ->contains(fn (int $workspaceId) => $request->user()->canManageWorkspace($workspaceId));

        abort_unless($canManageAnyWorkspace, 403);

        return Inertia::render('Settings/SystemHealth/Index', [
            'health' => $healthService->summary(),
        ]);
    }
}
