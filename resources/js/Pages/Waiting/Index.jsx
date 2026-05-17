import ObjectIndex from '@/Pages/CommandCenter/ObjectIndex';

export default function Index(props) {
    return <ObjectIndex {...props} title="Waiting" subtitle="Follow-up items and external dependencies." routeBase="waiting" closeLabel="Close" fields={['task', 'waiting_on', 'follow_up_date']} />;
}
