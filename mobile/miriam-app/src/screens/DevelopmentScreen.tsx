import React, { useEffect, useState } from 'react';
import { Alert, ScrollView, Text, View } from 'react-native';
import { useAuth } from '../auth/AuthContext';
import { PrimaryButton } from '../components/PrimaryButton';
import type { DevelopmentStatus } from '../types';
import { screenStyles as styles } from './screenStyles';

export function DevelopmentScreen() {
  const { api } = useAuth();
  const [status, setStatus] = useState<DevelopmentStatus | null>(null);

  async function load() {
    try {
      setStatus(await api.developmentStatus());
    } catch (error) {
      Alert.alert('Miriam', error instanceof Error ? error.message : 'Unable to load Codex status.');
    }
  }

  useEffect(() => {
    void load();
  }, []);

  return (
    <ScrollView style={styles.page}>
      <View style={styles.headerRow}>
        <Text style={styles.title}>Codex</Text>
        <PrimaryButton label="Refresh" tone="quiet" onPress={() => void load()} />
      </View>
      <DevelopmentCard title="Active Jobs" rows={status?.active_jobs || []} />
      <DevelopmentCard title="Needs Attention" rows={status?.needs_attention || []} />
      <DevelopmentCard title="Recent Ledger" rows={status?.recent_ledger || []} />
    </ScrollView>
  );
}

function DevelopmentCard({ title, rows }: { title: string; rows: Array<Record<string, unknown>> }) {
  return (
    <View style={styles.card}>
      <Text style={styles.cardTitle}>{title}</Text>
      {rows.length ? (
        rows.map((row, index) => (
          <Text key={`${title}-${index}`} style={styles.body}>
            {String(row.app_name || row.phase_name || row.status || 'Development item')} - {String(row.status || row.test_result || 'recorded')}
          </Text>
        ))
      ) : (
        <Text style={styles.empty}>Nothing here.</Text>
      )}
    </View>
  );
}
