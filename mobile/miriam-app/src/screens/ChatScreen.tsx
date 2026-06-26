import React, { useState } from 'react';
import { Alert, KeyboardAvoidingView, Platform, ScrollView, Text, TextInput, View } from 'react-native';
import { useAuth } from '../auth/AuthContext';
import { PrimaryButton } from '../components/PrimaryButton';
import { screenStyles as styles } from './screenStyles';

type Message = {
  id: string;
  from: 'you' | 'miriam';
  text: string;
};

export function ChatScreen() {
  const { api } = useAuth();
  const [messages, setMessages] = useState<Message[]>([
    { id: 'welcome', from: 'miriam', text: 'Ask Miriam about reminders, medication status, agenda, or Codex work.' },
  ]);
  const [input, setInput] = useState('');
  const [sending, setSending] = useState(false);

  async function send() {
    const text = input.trim();

    if (!text) {
      return;
    }

    setInput('');
    setSending(true);
    setMessages((current) => [...current, { id: `${Date.now()}-you`, from: 'you', text }]);

    try {
      const response = await api.chat(text);
      setMessages((current) => [...current, { id: `${Date.now()}-miriam`, from: 'miriam', text: response.reply }]);
    } catch (error) {
      Alert.alert('Miriam', error instanceof Error ? error.message : 'Unable to send message.');
    } finally {
      setSending(false);
    }
  }

  return (
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.page}>
      <Text style={styles.title}>Miriam Chat</Text>
      <ScrollView style={{ flex: 1 }} contentContainerStyle={{ paddingVertical: 12 }}>
        {messages.map((message) => (
          <View key={message.id} style={[styles.card, message.from === 'you' && { backgroundColor: '#e9f2ef' }]}>
            <Text style={styles.muted}>{message.from === 'you' ? 'You' : 'Miriam'}</Text>
            <Text style={styles.body}>{message.text}</Text>
          </View>
        ))}
      </ScrollView>
      <View style={styles.row}>
        <TextInput
          onChangeText={setInput}
          onSubmitEditing={() => void send()}
          placeholder="Ask Miriam..."
          returnKeyType="send"
          style={[styles.input, { flex: 1 }]}
          value={input}
        />
        <PrimaryButton label="Send" onPress={() => void send()} loading={sending} />
      </View>
    </KeyboardAvoidingView>
  );
}
