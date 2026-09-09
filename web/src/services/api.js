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

// Response interceptor — normalise every error into a standard { code, message, field } shape
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.data?.error) {
      error.apiError = error.response.data.error;
    } else if (error.response?.data?.message) {
      error.apiError = {
        code: error.response.status === 419 ? 'CSRF_MISMATCH' : 'HTTP_ERROR',
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
  getCsrfCookie: () => api.get('/sanctum/csrf-cookie'),

  // SPA session login.
  login: async (email, password, remember = false) => {
    await authApi.getCsrfCookie();
    const response = await api.post('/api/auth/login', { email, password, remember });
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
