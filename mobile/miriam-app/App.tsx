import React, { useMemo, useState } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  SafeAreaView,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  useWindowDimensions,
  View,
} from 'react-native';
import { AuthProvider, useAuth } from './src/auth/AuthContext';
import { AgendaScreen } from './src/screens/AgendaScreen';
import { ChatScreen } from './src/screens/ChatScreen';
import { DevelopmentScreen } from './src/screens/DevelopmentScreen';
import { HomeScreen } from './src/screens/HomeScreen';
import { MedicationScreen } from './src/screens/MedicationScreen';
import { NotificationsScreen } from './src/screens/NotificationsScreen';
import { RemindersScreen } from './src/screens/RemindersScreen';

type TabKey = 'home' | 'chat' | 'reminders' | 'medication' | 'agenda' | 'development' | 'notifications';

const tabs: Array<{ key: TabKey; label: string }> = [
  { key: 'home', label: 'Home' },
  { key: 'chat', label: 'Chat' },
  { key: 'reminders', label: 'Reminders' },
  { key: 'medication', label: 'Medication' },
  { key: 'agenda', label: 'Agenda' },
  { key: 'development', label: 'Codex' },
  { key: 'notifications', label: 'Alerts' },
];

export default function App() {
  return (
    <AuthProvider>
      <Root />
    </AuthProvider>
  );
}

function Root() {
  const { token, loading } = useAuth();

  if (loading) {
    return (
      <SafeAreaView style={styles.centered}>
        <ActivityIndicator size="large" color="#1d6f5f" />
      </SafeAreaView>
    );
  }

  return token ? <Shell /> : <LoginScreen />;
}

function LoginScreen() {
  const { login } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    setSubmitting(true);
    setError(null);

    try {
      await login(email.trim(), password);
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Unable to log in.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <SafeAreaView style={styles.loginPage}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.loginCard}>
        <Text style={styles.logo}>Miriam</Text>
        <Text style={styles.loginSubtitle}>Your private command center.</Text>
        <TextInput
          autoCapitalize="none"
          autoComplete="email"
          keyboardType="email-address"
          onChangeText={setEmail}
          placeholder="Email"
          style={styles.input}
          value={email}
        />
        <TextInput
          onChangeText={setPassword}
          placeholder="Password"
          secureTextEntry
          style={styles.input}
          value={password}
        />
        {error ? <Text style={styles.error}>{error}</Text> : null}
        <Pressable
          accessibilityRole="button"
          disabled={submitting || !email || !password}
          onPress={submit}
          style={[styles.loginButton, (submitting || !email || !password) && styles.disabled]}
        >
          <Text style={styles.loginButtonText}>{submitting ? 'Signing in...' : 'Sign in'}</Text>
        </Pressable>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

function Shell() {
  const { width } = useWindowDimensions();
  const isTablet = width >= 768;
  const [tab, setTab] = useState<TabKey>('home');
  const content = useMemo(() => renderScreen(tab), [tab]);

  return (
    <SafeAreaView style={styles.app}>
      <View style={[styles.shell, isTablet && styles.shellTablet]}>
        <View style={[styles.nav, isTablet && styles.navTablet]}>
          <Text style={styles.navTitle}>Miriam</Text>
          <ScrollView horizontal={!isTablet} showsHorizontalScrollIndicator={false}>
            <View style={[styles.navItems, !isTablet && styles.navItemsPhone]}>
              {tabs.map((item) => (
                <Pressable
                  accessibilityRole="tab"
                  key={item.key}
                  onPress={() => setTab(item.key)}
                  style={[styles.navItem, tab === item.key && styles.navItemActive]}
                >
                  <Text style={[styles.navItemText, tab === item.key && styles.navItemTextActive]}>{item.label}</Text>
                </Pressable>
              ))}
            </View>
          </ScrollView>
        </View>
        <View style={styles.content}>{content}</View>
      </View>
    </SafeAreaView>
  );
}

function renderScreen(tab: TabKey) {
  switch (tab) {
    case 'chat':
      return <ChatScreen />;
    case 'reminders':
      return <RemindersScreen />;
    case 'medication':
      return <MedicationScreen />;
    case 'agenda':
      return <AgendaScreen />;
    case 'development':
      return <DevelopmentScreen />;
    case 'notifications':
      return <NotificationsScreen />;
    case 'home':
    default:
      return <HomeScreen />;
  }
}

const styles = StyleSheet.create({
  app: {
    flex: 1,
    backgroundColor: '#f6f4ef',
  },
  centered: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#f6f4ef',
  },
  loginPage: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#16201f',
    padding: 20,
  },
  loginCard: {
    width: '100%',
    maxWidth: 420,
    gap: 14,
  },
  logo: {
    color: '#fff',
    fontSize: 36,
    fontWeight: '800',
  },
  loginSubtitle: {
    color: '#c6d2cf',
    fontSize: 16,
    marginBottom: 10,
  },
  input: {
    minHeight: 48,
    borderRadius: 8,
    backgroundColor: '#fff',
    paddingHorizontal: 14,
    fontSize: 16,
  },
  error: {
    color: '#ffd8d3',
  },
  loginButton: {
    minHeight: 48,
    borderRadius: 8,
    backgroundColor: '#33a287',
    alignItems: 'center',
    justifyContent: 'center',
  },
  loginButtonText: {
    color: '#fff',
    fontSize: 16,
    fontWeight: '800',
  },
  disabled: {
    opacity: 0.55,
  },
  shell: {
    flex: 1,
  },
  shellTablet: {
    flexDirection: 'row',
  },
  nav: {
    backgroundColor: '#16201f',
    paddingHorizontal: 14,
    paddingTop: 10,
    paddingBottom: 8,
  },
  navTablet: {
    width: 228,
    paddingTop: 22,
  },
  navTitle: {
    color: '#fff',
    fontSize: 22,
    fontWeight: '800',
    marginBottom: 12,
  },
  navItems: {
    gap: 8,
  },
  navItemsPhone: {
    flexDirection: 'row',
  },
  navItem: {
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 10,
  },
  navItemActive: {
    backgroundColor: '#27433e',
  },
  navItemText: {
    color: '#d9e2de',
    fontWeight: '700',
  },
  navItemTextActive: {
    color: '#fff',
  },
  content: {
    flex: 1,
  },
});
