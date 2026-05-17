import ObjectIndex from '@/Pages/CommandCenter/ObjectIndex';

export default function Index(props) {
    return <ObjectIndex {...props} title="Approvals" subtitle="Approval requests that need a clear yes or no." routeBase="approvals" closeLabel="Approve" reject fields={['task', 'requested_by']} />;
}
