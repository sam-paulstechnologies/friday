import React from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text } from 'react-native';

type Props = {
  label: string;
  onPress: () => void;
  disabled?: boolean;
  loading?: boolean;
  tone?: 'primary' | 'quiet' | 'danger';
};

export function PrimaryButton({ label, onPress, disabled = false, loading = false, tone = 'primary' }: Props) {
  return (
    <Pressable
      accessibilityRole="button"
      disabled={disabled || loading}
      onPress={onPress}
      style={({ pressed }) => [
        styles.button,
        styles[tone],
        (disabled || loading) && styles.disabled,
        pressed && styles.pressed,
      ]}
    >
      {loading ? <ActivityIndicator color="#fff" /> : <Text style={[styles.label, tone === 'quiet' && styles.quietLabel]}>{label}</Text>}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  button: {
    minHeight: 44,
    borderRadius: 8,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 14,
    paddingVertical: 10,
  },
  primary: {
    backgroundColor: '#1d6f5f',
  },
  quiet: {
    backgroundColor: '#e8efec',
  },
  danger: {
    backgroundColor: '#a6423a',
  },
  disabled: {
    opacity: 0.55,
  },
  pressed: {
    opacity: 0.82,
  },
  label: {
    color: '#fff',
    fontSize: 15,
    fontWeight: '700',
  },
  quietLabel: {
    color: '#19312d',
  },
});
