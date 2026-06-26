import React, { useEffect, useState } from 'react';
import { Alert, ScrollView, Text, View } from 'react-native';
import { useAuth } from '../auth/AuthContext';
import { PrimaryButton } from '../components/PrimaryButton';
import type { Reminder } from '../types';
import { formatDateTime } from '../utils/date';
import { screenStyles as styles } from './screenStyles';

export function RemindersScreen() {
  const { api } = useAuth();
  const [reminders, setReminders] = useState<Reminder[]>([]);
  const [loading, setLoading] = useState(false);

  async function load() {
    setLoading(true);
    try {
      const response = await api.reminders();
      setReminders(response.data);
    } catch (error) {
      Alert.alert('Miriam', error instanceof Error ? error.message : 'Unable to load reminders.');
    } finally {
      setLoading(false);
    }
  }

  async function act(id: number, action: 'done' | 'snooze' | 'cancel') {
    await api.reminderAction(id, action);
    await load();
  }

  useEffect(() => {
    void load();
  }, []);

  return (
    <ScrollView style={styles.page}>
      <View style={styles.headerRow}>
        <Text style={styles.title}>Reminders</Text>
        <PrimaryButton label="Refresh" tone="quiet" onPress={() => void load()} loading={loading} />
      </View>
      {reminders.length ? (
        reminders.map((reminder) => (
          <View key={reminder.id} style={styles.card}>
            <Text style={styles.cardTitle}>{reminder.title}</Text>
            <Text style={styles.muted}>{formatDateTime(reminder.due_at)} - {reminder.status}</Text>
            <View style={[styles.row, { marginTop: 10 }]}>
              <PrimaryButton label="Done" onPress={() => void act(reminder.id, 'done')} />
              <PrimaryButton label="Snooze" tone="quiet" onPress={() => void act(reminder.id, 'snooze')} />
              <PrimaryButton label="Cancel" tone="danger" onPress={() => void act(reminder.id, 'cancel')} />
            </View>
          </View>
        ))
      ) : (
        <Text style={styles.empty}>No pending reminders.</Text>
      )}
    </ScrollView>
  );
}
