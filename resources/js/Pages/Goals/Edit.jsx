import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import GoalForm from './Partials/GoalForm';

export default function Edit(props) {
    return (
        <AuthenticatedLayout title="Edit Goal" subtitle="Update objective details, projects, and progress.">
            <Head title="Edit Goal" />
            <div className="rounded-lg border border-slate-200 bg-white p-5">
                <GoalForm {...props} />
            </div>
        </AuthenticatedLayout>
    );
}
