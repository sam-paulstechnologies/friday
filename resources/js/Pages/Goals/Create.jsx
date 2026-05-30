import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import GoalForm from './Partials/GoalForm';

export default function Create(props) {
    return (
        <AuthenticatedLayout title="Create Goal" subtitle="Add an objective and link it to projects.">
            <Head title="Create Goal" />
            <div className="rounded-lg border border-slate-200 bg-white p-5">
                <GoalForm {...props} />
            </div>
        </AuthenticatedLayout>
    );
}
