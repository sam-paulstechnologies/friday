import * as SecureStore from 'expo-secure-store';
import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { createMobileApi, login as loginRequest } from '../api/client';
import type { Dashboard, User } from '../types';

type AuthState = {
  token: string | null;
  user: User | null;
  dashboard: Dashboard | null;
  loading: boolean;
  api: ReturnType<typeof createMobileApi>;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  refresh: () => Promise<void>;
};

const TOKEN_KEY = 'miriam.mobile.token';
const USER_KEY = 'miriam.mobile.user';

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [token, setToken] = useState<string | null>(null);
  const [user, setUser] = useState<User | null>(null);
  const [dashboard, setDashboard] = useState<Dashboard | null>(null);
  const [loading, setLoading] = useState(true);
  const api = useMemo(() => createMobileApi(token), [token]);

  const refresh = useCallback(async () => {
    if (!token) {
      return;
    }

    const response = await createMobileApi(token).me();
    setUser(response.user);
    setDashboard(response.dashboard);
    await SecureStore.setItemAsync(USER_KEY, JSON.stringify(response.user));
  }, [token]);

  useEffect(() => {
    let mounted = true;

    async function hydrate() {
      try {
        const storedToken = await SecureStore.getItemAsync(TOKEN_KEY);
        const storedUser = await SecureStore.getItemAsync(USER_KEY);

        if (!mounted) {
          return;
        }

        setToken(storedToken);
        setUser(storedUser ? JSON.parse(storedUser) as User : null);
      } finally {
        if (mounted) {
          setLoading(false);
        }
      }
    }

    void hydrate();

    return () => {
      mounted = false;
    };
  }, []);

  useEffect(() => {
    if (token) {
      void refresh().catch(() => undefined);
    }
  }, [refresh, token]);

  const login = useCallback(async (email: string, password: string) => {
    const response = await loginRequest(email, password, 'Miriam mobile');
    setToken(response.token);
    setUser(response.user);
    await SecureStore.setItemAsync(TOKEN_KEY, response.token);
    await SecureStore.setItemAsync(USER_KEY, JSON.stringify(response.user));
  }, []);

  const logout = useCallback(async () => {
    try {
      if (token) {
        await createMobileApi(token).logout();
      }
    } finally {
      setToken(null);
      setUser(null);
      setDashboard(null);
      await SecureStore.deleteItemAsync(TOKEN_KEY);
      await SecureStore.deleteItemAsync(USER_KEY);
    }
  }, [token]);

  return (
    <AuthContext.Provider value={{ token, user, dashboard, loading, api, login, logout, refresh }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const context = useContext(AuthContext);

  if (!context) {
    throw new Error('useAuth must be used inside AuthProvider');
  }

  return context;
}
