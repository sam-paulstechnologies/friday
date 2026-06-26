import React from 'react';
import { ScrollView, Text, View } from 'react-native';
import * as Notifications from 'expo-notifications';
import { PrimaryButton } from '../components/PrimaryButton';
import { screenStyles as styles } from './screenStyles';

export function NotificationsScreen() {
  async function requestPermission() {
    await Notifications.requestPermissionsAsync();
  }

  return (
    <ScrollView style={styles.page}>
      <Text style={styles.title}>Notifications</Text>
      <View style={styles.card}>
        <Text style={styles.cardTitle}>MVP Delivery</Text>
        <Text style={styles.body}>
          Miriam keeps medication reminders on the backend through Slack and database notifications. This app is ready to request Expo push permissions when push delivery is enabled.
        </Text>
        <View style={{ marginTop: 12 }}>
          <PrimaryButton label="Enable push permission" onPress={() => void requestPermission()} />
        </View>
      </View>
    </ScrollView>
  );
}
