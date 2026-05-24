import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import ProjectForm from './Partials/ProjectForm';

export default function Edit({ project, workspaces, teams, statuses, visibilities }) {
    return (
        <AuthenticatedLayout title="Edit Project" subtitle={project.name}>
            <Head title={`Edit ${project.name}`} />

            <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-200/70 sm:p-6">
                <ProjectForm
                    project={project}
                    workspaces={workspaces}
                    teams={teams}
                    statuses={statuses}
                    visibilities={visibilities}
                    submitLabel="Save Changes"
                />
            </div>
        </AuthenticatedLayout>
    );
}
