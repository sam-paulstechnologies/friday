import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import OperationsGraph from '@/Components/OperationsCenter/OperationsGraph';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ initialView = 'journey-flow', initialGraph, tabs = [], endpoints = {}, permissions = {} }) {
    const [activeView, setActiveView] = useState(initialView);

    return (
        <AuthenticatedLayout
            title="Operations Center"
            subtitle="Visualize, understand, and execute Miriam's capture-first operating system."
        >
            <Head title="Miriam Operations Center" />

            <div className="space-y-4">
                <div className="rounded-lg border border-violet-200 bg-violet-50/70 px-4 py-3 text-sm text-violet-900">
                    Capture is the center of Miriam. The graph loads summaries first, then details on demand, so private records and logs are not pushed into the initial page payload.
                    {!permissions.technical_map && <span className="ml-1 font-semibold">Technical Map requires owner or admin workspace access.</span>}
                </div>

                <OperationsGraph
                    initialGraph={initialGraph}
                    tabs={tabs}
                    activeView={activeView}
                    onViewChange={setActiveView}
                    graphEndpoint={endpoints.graph}
                    detailsEndpoint={endpoints.details}
                />
            </div>
        </AuthenticatedLayout>
    );
}
