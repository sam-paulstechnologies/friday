import ObjectIndex from '@/Pages/CommandCenter/ObjectIndex';

export default function Index(props) {
    return <ObjectIndex {...props} title="Decisions" subtitle="Pending decisions that need a clear owner and deadline." routeBase="decisions" closeLabel="Decide" fields={['decision', 'decision_due_date']} />;
}
