import React, { useEffect, useState } from 'react';
import { Alert, ScrollView, Text, View } from 'react-native';
import { PrimaryButton } from '../components/PrimaryButton';
import { StatusPill } from '../components/StatusPill';
import { useAuth } from '../auth/AuthContext';
import type { Dashboard } from '../types';
import { formatDateTime } from '../utils/date';
import { screenStyles as styles } from './screenStyles';

export function HomeScreen() {
  const { api, dashboard, logout, refresh, user } = useAuth();
  const [data, setData] = useState<Dashboard | null>(dashboard);
  const [loading, setLoading] = useState(false);

  async function load() {
    setLoading(true);
    try {
      const response = await api.me();
      setData(response.dashboard);
      await refresh();
    } catch (error) {
      Alert.alert('Miriam', error instanceof Error ? error.message : 'Unable to load dashboard.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void load();
  }, []);

  return (
    <ScrollView style={styles.page}>
      <View style={styles.headerRow}>
        <View>
          <Text style={styles.title}>Today</Text>
          <Text style={styles.subtitle}>{user ? `Signed in as ${user.email}` : 'Miriam dashboard'}</Text>
        </View>
        <PrimaryButton label="Logout" tone="quiet" onPress={() => void logout()} />
      </View>

      <View style={styles.card}>
        <Text style={styles.cardTitle}>Summary</Text>
        {data ? (
          <View style={styles.row}>
            <StatusPill label={`${data.today_summary.pending_reminders} reminders`} />
            <StatusPill label={`${data.today_summary.medication_pending} meds pending`} />
            <StatusPill label={`${data.today_summary.active_development_jobs} Codex jobs`} />
          </View>
        ) : (
          <Text style={styles.empty}>No dashboard data yet.</Text>
        )}
      </View>

      <View style={styles.card}>
        <Text style={styles.cardTitle}>Next Reminders</Text>
        {data?.next_reminders.length ? (
          data.next_reminders.map((reminder) => (
            <Text key={reminder.id} style={styles.body}>
              {formatDateTime(reminder.due_at)} - {reminder.title}
            </Text>
          ))
        ) : (
          <Text style={styles.empty}>No pending reminders.</Text>
        )}
      </View>

      <View style={styles.card}>
        <Text style={styles.cardTitle}>Medication</Text>
        {data?.medication.today.length ? (
          data.medication.today.map((dose) => (
            <Text key={dose.id} style={styles.body}>
              {dose.label}: {dose.status}
            </Text>
          ))
        ) : (
          <Text style={styles.empty}>No dose logs loaded for today.</Text>
        )}
      </View>

      <PrimaryButton label={loading ? 'Refreshing...' : 'Refresh'} onPress={() => void load()} loading={loading} />
    </ScrollView>
  );
}
