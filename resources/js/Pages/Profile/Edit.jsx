import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { Panel } from '@/Components/Ui';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit({ mustVerifyEmail, status }) {
    return (
        <AuthenticatedLayout title="Profile" subtitle="Account settings, password, and data controls.">
            <Head title="Profile" />

            <div className="mx-auto max-w-5xl space-y-6">
                    <Panel className="p-5 sm:p-6">
                        <UpdateProfileInformationForm
                            mustVerifyEmail={mustVerifyEmail}
                            status={status}
                            className="max-w-xl"
                        />
                    </Panel>

                    <Panel className="p-5 sm:p-6">
                        <UpdatePasswordForm className="max-w-xl" />
                    </Panel>

                    <Panel className="p-5 sm:p-6">
                        <DeleteUserForm className="max-w-xl" />
                    </Panel>
            </div>
        </AuthenticatedLayout>
    );
}
