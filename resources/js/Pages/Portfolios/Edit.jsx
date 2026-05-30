import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import PortfolioForm from './Partials/PortfolioForm';

export default function Edit(props) {
    return (
        <AuthenticatedLayout title="Edit Portfolio" subtitle="Update portfolio ownership, area, and status.">
            <Head title="Edit Portfolio" />
            <div className="rounded-lg border border-slate-200 bg-white p-5">
                <PortfolioForm {...props} />
            </div>
        </AuthenticatedLayout>
    );
}
