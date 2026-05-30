import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import TaskForm from './Partials/TaskForm';

export default function Create({ prefilledProject, workspaces, projects, users, labels = [], statuses, priorities, recurrenceTypes = [] }) {
    return (
        <AuthenticatedLayout
            title="Create Task"
            subtitle={prefilledProject ? `Add work to ${prefilledProject.name}` : 'Add a task to your workspace.'}
        >
            <Head title="Create Task" />

            <div data-testid="task-create-page" className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-200/70 sm:p-6">
                <TaskForm
                    prefilledProject={prefilledProject}
                    workspaces={workspaces}
                    projects={projects}
                    users={users}
                    labels={labels}
                    statuses={statuses}
                    priorities={priorities}
                    recurrenceTypes={recurrenceTypes}
                    submitLabel="Create Task"
                />
            </div>
        </AuthenticatedLayout>
    );
}
