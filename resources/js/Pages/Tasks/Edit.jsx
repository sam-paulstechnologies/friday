import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import TaskForm from './Partials/TaskForm';

export default function Edit({ task, workspaces, projects, users, statuses, priorities }) {
    return (
        <AuthenticatedLayout title="Edit Task" subtitle={task.title}>
            <Head title={`Edit ${task.title}`} />

            <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-200/70 sm:p-6">
                <TaskForm
                    task={task}
                    workspaces={workspaces}
                    projects={projects}
                    users={users}
                    statuses={statuses}
                    priorities={priorities}
                    submitLabel="Save Changes"
                />
            </div>
        </AuthenticatedLayout>
    );
}
