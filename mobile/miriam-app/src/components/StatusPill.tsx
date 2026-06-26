import React from 'react';
import { StyleSheet, Text, View } from 'react-native';

export function StatusPill({ label }: { label: string }) {
  return (
    <View style={styles.pill}>
      <Text style={styles.text}>{label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  pill: {
    alignSelf: 'flex-start',
    borderRadius: 999,
    backgroundColor: '#e7f1ee',
    paddingHorizontal: 10,
    paddingVertical: 5,
  },
  text: {
    color: '#1d564b',
    fontSize: 12,
    fontWeight: '700',
  },
});
