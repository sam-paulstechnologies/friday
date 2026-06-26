import React, { useEffect, useState } from 'react';
import { Alert, ScrollView, Text, TextInput, View } from 'react-native';
import { useAuth } from '../auth/AuthContext';
import { PrimaryButton } from '../components/PrimaryButton';
import type { MedicationDose, MedicationStatus } from '../types';
import { formatDateTime } from '../utils/date';
import { screenStyles as styles } from './screenStyles';

export function MedicationScreen() {
  const { api } = useAuth();
  const [status, setStatus] = useState<MedicationStatus | null>(null);
  const [skipReasons, setSkipReasons] = useState<Record<number, string>>({});

  async function load() {
    const response = await api.medicationStatus();
    setStatus(response);
  }

  async function action(dose: MedicationDose, type: 'taken' | 'snooze' | 'skip') {
    const payload = type === 'skip' ? { reason: skipReasons[dose.id] || '' } : type === 'snooze' ? { minutes: 15 } : {};

    if (type === 'skip' && !payload.reason) {
      Alert.alert('Skip reason required', 'Please enter a reason before skipping.');
      return;
    }

    await api.medicationAction(dose.id, type, payload);
    await load();
  }

  useEffect(() => {
    void load().catch((error) => Alert.alert('Miriam', error instanceof Error ? error.message : 'Unable to load medication.'));
  }, []);

  return (
    <ScrollView style={styles.page}>
      <Text style={styles.title}>Medication</Text>
      <Text style={styles.subtitle}>Routine and today status come from Miriam backend.</Text>

      <View style={styles.card}>
        <Text style={styles.cardTitle}>Routine</Text>
        {status?.routine.map((item) => (
          <View key={`${item.dose_key}-${item.schedule_id}`} style={{ marginBottom: 10 }}>
            <Text style={styles.body}>{item.label} - {item.time || 'Scheduled'}</Text>
            {item.medications.map((medication) => (
              <Text key={medication} style={styles.muted}>- {medication}</Text>
            ))}
          </View>
        ))}
      </View>

      <View style={styles.card}>
        <Text style={styles.cardTitle}>Today</Text>
        {status?.today.length ? (
          status.today.map((dose) => (
            <View key={dose.id} style={{ marginBottom: 14 }}>
              <Text style={styles.body}>{dose.label}: {dose.status}</Text>
              <Text style={styles.muted}>{formatDateTime(dose.scheduled_for)}</Text>
              <TextInput
                onChangeText={(text) => setSkipReasons((current) => ({ ...current, [dose.id]: text }))}
                placeholder="Skip reason"
                style={[styles.input, { marginTop: 8 }]}
                value={skipReasons[dose.id] || ''}
              />
              <View style={[styles.row, { marginTop: 8 }]}>
                <PrimaryButton label="Taken" onPress={() => void action(dose, 'taken')} />
                <PrimaryButton label="Snooze" tone="quiet" onPress={() => void action(dose, 'snooze')} />
                <PrimaryButton label="Skip" tone="danger" onPress={() => void action(dose, 'skip')} />
              </View>
            </View>
          ))
        ) : (
          <Text style={styles.empty}>No medication dose logs for today.</Text>
        )}
      </View>
    </ScrollView>
  );
}
