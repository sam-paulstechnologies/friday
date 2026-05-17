import ObjectIndex from '@/Pages/CommandCenter/ObjectIndex';

export default function Index(props) {
    return <ObjectIndex {...props} title="Blockers" subtitle="Open blockers that are slowing execution." routeBase="blockers" closeLabel="Resolve" fields={['task', 'severity']} />;
}
