import ObjectIndex from '@/Pages/CommandCenter/ObjectIndex';

export default function Index(props) {
    return <ObjectIndex {...props} title="Risks" subtitle="Risks, probability, impact, and mitigation notes." routeBase="risks" closeLabel="Close" fields={['impact', 'probability', 'mitigation']} />;
}
