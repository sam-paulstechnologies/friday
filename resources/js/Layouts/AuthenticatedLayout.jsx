import AppShell from './AppShell';

/**
 * Compatibility wrapper.
 *
 * Every authenticated page already renders through this component, so pointing
 * it at AppShell gives the whole application the new shell, navigation, Quick
 * Add and feedback patterns without touching each page individually.
 *
 * New pages should import AppShell directly.
 */
export default function AuthenticatedLayout(props) {
    return <AppShell {...props} />;
}
