import axios from 'axios';

// Cookie-session only (Sanctum SPA auth).
// No Bearer tokens, no localStorage — the session cookie is the auth credential.
const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '',
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
  withCredentials: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
});

// ─── 419 Auto-retry + error-normalising interceptor ──────────────────────────
// When the server returns 419 (CSRF token mismatch), fetch a fresh CSRF cookie
// and automatically retry the original request once. This handles the case
// where the session was regenerated (e.g. after login) and the browser's
// XSRF-TOKEN cookie became stale.
let _csrfRefreshing = false;
api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const status = error.response?.status;

    // Auto-retry once on 419 CSRF mismatch
    if (status === 419 && !error.config.__csrfRetried && !_csrfRefreshing) {
      error.config.__csrfRetried = true;
      _csrfRefreshing = true;
      try {
        await api.get(`/sanctum/csrf-cookie?_t=${Date.now()}`);
      } finally {
        _csrfRefreshing = false;
      }
      return api(error.config);
    }

    // Normalise all other errors into { code, message, field }
    if (error.response?.data?.error) {
      error.apiError = error.response.data.error;
    } else if (error.response?.data?.message) {
      error.apiError = {
        code: status === 419 ? 'CSRF_MISMATCH' : 'HTTP_ERROR',
        message: error.response.data.message,
        field: null,
      };
    } else {
      error.apiError = {
        code: 'NETWORK_ERROR',
        message: error.message || 'A network error occurred. Please try again.',
        field: null,
      };
    }

    return Promise.reject(error);
  }
);

export const authApi = {
  // Must be called before login to seed XSRF-TOKEN and autopilot_session cookies.
  getCsrfCookie: () => api.get(`/sanctum/csrf-cookie?_t=${Date.now()}`),

  // SPA session login.
  login: async (email, password, remember = false) => {
    // 1. Pre-fetch CSRF cookie to seed the session
    try {
      await authApi.getCsrfCookie();
    } catch {
      // Continue even if preflight fails; the 419 interceptor will retry if needed
    }
    // 2. Login (exempted from CSRF validation on the server)
    const response = await api.post('/api/auth/login', { email, password, remember });
    // 3. CRITICAL: After login Laravel regenerates the session, which creates a new
    //    CSRF token. Re-fetch the csrf-cookie so the browser's XSRF-TOKEN cookie
    //    is synced with the new session. Without this, ALL subsequent POST/PUT/DELETE
    //    requests will fail with 419 CSRF token mismatch.
    try {
      await authApi.getCsrfCookie();
    } catch {
      // Non-fatal; the 419 interceptor will handle retries if needed
    }
    return response.data;
  },

  // Logout — invalidates the session server-side; cookies are cleared by the response.
  logout: async () => {
    const response = await api.post('/api/auth/logout');
    return response.data;
  },

  // Fetch the currently authenticated user via session cookie.
  me: async () => {
    const response = await api.get('/api/auth/me');
    return response.data;
  },
};

export const myTasksApi = {
  // GET /api/my-tasks — returns { overdue, due_today, upcoming, open_tasks }
  index: () => api.get('/api/my-tasks/'),

  // POST /api/my-tasks/{id}/start
  start: (id) => api.post(`/api/my-tasks/${id}/start`),

  // POST /api/my-tasks/{id}/complete
  complete: (id) => api.post(`/api/my-tasks/${id}/complete`),

  // POST /api/my-tasks/{id}/block  { reason_code, description?, depends_on_task_id? }
  block: (id, payload) => api.post(`/api/my-tasks/${id}/block`, payload),

  // POST /api/my-tasks/{id}/unblock
  unblock: (id) => api.post(`/api/my-tasks/${id}/unblock`),

  // POST /api/my-tasks/{id}/comment  { body }
  comment: (id, body) => api.post(`/api/my-tasks/${id}/comment`, { body }),

  // POST /api/my-tasks/{id}/attach  (multipart)
  attach: (id, file) => {
    const form = new FormData();
    form.append('file', file);
    return api.post(`/api/my-tasks/${id}/attach`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
  },
};

export const managerApi = {
  // GET /api/manager/dashboard
  dashboard: (params) => api.get('/api/manager/dashboard', { params }),

  // POST /api/manager/tasks/{id}/reassign  { owner_id }
  reassign: (id, ownerId) => api.post(`/api/manager/tasks/${id}/reassign`, { owner_id: ownerId }),

  // PATCH /api/manager/tasks/{id}/deadline  { due_date }
  updateDeadline: (id, dueDate) => api.patch(`/api/manager/tasks/${id}/deadline`, { due_date: dueDate }),

  // POST /api/manager/tasks/{id}/comment  { body }
  comment: (id, body) => api.post(`/api/manager/tasks/${id}/comment`, { body }),

  // POST /api/manager/tasks/{id}/resolve-blocker
  resolveBlocker: (id) => api.post(`/api/manager/tasks/${id}/resolve-blocker`),

  // POST /api/manager/tasks/{id}/escalate  { escalation_level? }
  escalate: (id, escalationLevel) => api.post(`/api/manager/tasks/${id}/escalate`, { escalation_level: escalationLevel }),
};

export const executiveApi = {
  // GET /api/executive/dashboard
  dashboard: () => api.get('/api/executive/dashboard'),
};

export const adminApi = {
  // Users
  getUsers: () => api.get('/api/admin/users'),
  createUser: (userData) => api.post('/api/admin/users', userData),
  updateUser: (id, userData) => api.put(`/api/admin/users/${id}`, userData),

  // Settings
  getSettings: () => api.get('/api/admin/settings'),
  updateSettings: (settingsData) => api.put('/api/admin/settings', settingsData),
  regenerateApiKey: () => api.post('/api/admin/api-key/regenerate'),

  // Webhooks
  getWebhooks: (limit = 50) => api.get(`/api/admin/webhooks?limit=${limit}`),
  resendWebhook: (id) => api.post(`/api/admin/webhooks/${id}/resend`),
};

export default api;
