const fs = require('fs');
const path = require('path');

const required = [
  'App.tsx',
  'src/api/client.ts',
  'src/auth/AuthContext.tsx',
  'src/screens/HomeScreen.tsx',
  'src/screens/ChatScreen.tsx',
  'src/screens/RemindersScreen.tsx',
  'src/screens/MedicationScreen.tsx',
  'src/screens/AgendaScreen.tsx',
  'src/screens/DevelopmentScreen.tsx',
];

const missing = required.filter((file) => !fs.existsSync(path.join(__dirname, '..', file)));

if (missing.length > 0) {
  console.error(`Missing Miriam mobile files: ${missing.join(', ')}`);
  process.exit(1);
}

console.log('Miriam mobile smoke check passed.');
