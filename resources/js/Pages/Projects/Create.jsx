import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import ProjectForm from './Partials/ProjectForm';

export default function Create({ workspaces, teams, statuses, visibilities }) {
    return (
        <AuthenticatedLayout title="Create Project" subtitle="Set up a project shell for future task planning.">
            <Head title="Create Project" />

            <div className="rounded-md border border-slate-200 bg-white p-5 shadow-sm">
                <ProjectForm
                    workspaces={workspaces}
                    teams={teams}
                    statuses={statuses}
                    visibilities={visibilities}
                    submitLabel="Create Project"
                />
            </div>
        </AuthenticatedLayout>
    );
}
