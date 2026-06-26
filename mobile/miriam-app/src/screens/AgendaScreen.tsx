import React, { useEffect, useState } from 'react';
import { Alert, ScrollView, Text, View } from 'react-native';
import { useAuth } from '../auth/AuthContext';
import { PrimaryButton } from '../components/PrimaryButton';
import { StatusPill } from '../components/StatusPill';
import type { AgendaItem } from '../types';
import { formatDateTime } from '../utils/date';
import { screenStyles as styles } from './screenStyles';

type Period = 'today' | 'tomorrow' | 'upcoming';

export function AgendaScreen() {
  const { api } = useAuth();
  const [period, setPeriod] = useState<Period>('today');
  const [items, setItems] = useState<AgendaItem[]>([]);

  async function load(nextPeriod = period) {
    try {
      const response = await api.agenda(nextPeriod);
      setItems(response.items);
      setPeriod(nextPeriod);
    } catch (error) {
      Alert.alert('Miriam', error instanceof Error ? error.message : 'Unable to load agenda.');
    }
  }

  useEffect(() => {
    void load('today');
  }, []);

  return (
    <ScrollView style={styles.page}>
      <Text style={styles.title}>Agenda</Text>
      <View style={[styles.row, { marginVertical: 12 }]}>
        {(['today', 'tomorrow', 'upcoming'] as Period[]).map((option) => (
          <PrimaryButton
            key={option}
            label={option[0].toUpperCase() + option.slice(1)}
            tone={period === option ? 'primary' : 'quiet'}
            onPress={() => void load(option)}
          />
        ))}
      </View>
      {items.length ? (
        items.map((item) => (
          <View key={`${item.type}-${item.id}`} style={styles.card}>
            <View style={styles.row}>
              <StatusPill label={item.type} />
              {item.status ? <StatusPill label={item.status} /> : null}
            </View>
            <Text style={[styles.cardTitle, { marginTop: 8 }]}>{item.title}</Text>
            <Text style={styles.muted}>{formatDateTime(item.starts_at)}</Text>
          </View>
        ))
      ) : (
        <Text style={styles.empty}>No agenda items for this view.</Text>
      )}
    </ScrollView>
  );
}
