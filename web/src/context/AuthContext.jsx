import React, { createContext, useContext, useState, useEffect, useCallback } from 'react';
import { authApi } from '../services/api';

const AuthContext = createContext(null);

export const getDefaultRouteForRole = (role) => {
  switch (role) {
    case 'admin':
      return '/admin';
    case 'manager':
      return '/manager';
    case 'executive':
      return '/executive';
    case 'employee':
    default:
      return '/my-tasks';
  }
};

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  // On mount, probe the session via GET /api/auth/me.
  // Auth is cookie-session only — no localStorage token check.
  // If the session cookie is valid the server returns the user profile.
  // Any 401 (including stale remember_web_* cleared by the middleware) means
  // the user is logged out; we stay on the login page.
  const checkAuth = useCallback(async () => {
    try {
      setLoading(true);
      const data = await authApi.me();
      if (data?.user) {
        setUser(data.user);
      } else {
        setUser(null);
      }
    } catch {
      // 401 = not authenticated, any other error is treated the same way
      setUser(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    checkAuth();
  }, [checkAuth]);

  const login = async (email, password, remember = false) => {
    setError(null);
    try {
      const data = await authApi.login(email, password, remember);
      if (data?.user) {
        setUser(data.user);
        return data.user;
      }
      throw new Error('User data missing in login response.');
    } catch (err) {
      const apiErr = err.apiError || {
        code: 'LOGIN_FAILED',
        message: err.message || 'Login failed. Please check your credentials.',
      };
      setError(apiErr);
      throw apiErr;
    }
  };

  const logout = async () => {
    try {
      await authApi.logout();
    } catch (err) {
      console.warn('Logout request failed or already invalidated:', err);
    } finally {
      setUser(null);
    }
  };

  const refreshUser = async () => {
    try {
      const data = await authApi.me();
      if (data?.user) {
        setUser(data.user);
      }
    } catch (err) {
      console.warn('Failed to refresh user profile:', err);
    }
  };

  const value = {
    user,
    loading,
    error,
    login,
    logout,
    refreshUser,
    isAuthenticated: !!user,
    role: user?.role,
    getDefaultRoute: () => (user ? getDefaultRouteForRole(user.role) : '/login'),
  };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
};

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
};
