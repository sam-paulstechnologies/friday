import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import PortfolioForm from './Partials/PortfolioForm';

export default function Create(props) {
    return (
        <AuthenticatedLayout title="Create Portfolio" subtitle="Group projects for leadership visibility.">
            <Head title="Create Portfolio" />
            <div className="rounded-lg border border-slate-200 bg-white p-5">
                <PortfolioForm {...props} />
            </div>
        </AuthenticatedLayout>
    );
}
