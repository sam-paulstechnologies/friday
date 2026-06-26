# Miriam Mobile

Native Expo React Native MVP for Miriam.

## Local Setup

```bash
cd mobile/miriam-app
npm install
cp .env.example .env
npm run typecheck
npx expo config --type public
npm start
```

Set `EXPO_PUBLIC_MIRIAM_API_BASE_URL` to the Friday backend URL when testing another environment. Do not put secrets in the app.

## Android Install Options

For a local Android dev build:

```bash
cd mobile/miriam-app
npm install
npx expo run:android
```

For an installable APK/dev build with EAS:

```bash
npm install -g eas-cli
cd mobile/miriam-app
eas build:configure
eas build --platform android --profile development
```

Medication reminders still run through the backend Slack/database reminder system until mobile push delivery is fully verified.
