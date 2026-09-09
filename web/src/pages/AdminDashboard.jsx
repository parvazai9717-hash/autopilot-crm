import React, { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { adminApi } from '../services/api';
import {
  Settings,
  Sliders,
  Webhook,
  Users,
  FileCheck2,
  ShieldCheck,
  ArrowRight,
  Clock,
  AlertTriangle,
  CheckCircle2,
  Copy,
  RefreshCw,
  Plus,
  Edit2,
  X,
  Loader2,
  Key,
  ExternalLink,
  Send,
  Eye,
  Check,
  AlertOctagon,
  ShieldAlert,
} from 'lucide-react';

const ROLE_BADGES = {
  admin:     'text-indigo-400 bg-indigo-500/10 border-indigo-500/30',
  executive: 'text-purple-400 bg-purple-500/10 border-purple-500/30',
  manager:   'text-sky-400    bg-sky-500/10    border-sky-500/30',
  employee:  'text-emerald-400 bg-emerald-500/10 border-emerald-500/30',
};

const DAY_NAMES = [
  { id: 1, name: 'Monday' },
  { id: 2, name: 'Tuesday' },
  { id: 3, name: 'Wednesday' },
  { id: 4, name: 'Thursday' },
  { id: 5, name: 'Friday' },
  { id: 6, name: 'Saturday' },
  { id: 7, name: 'Sunday' },
];

export const AdminDashboard = () => {
  const { user } = useAuth();

  // Active sub-tab
  const [activeTab, setActiveTab] = useState('policy'); // 'policy' | 'api_webhooks' | 'users' | 'webhooks'

  // Loading states
  const [loading, setLoading] = useState(true);
  const [savingSettings, setSavingSettings] = useState(false);
  const [resendingWebhookId, setResendingWebhookId] = useState(null);
  const [toastMessage, setToastMessage] = useState(null);
  const [formError, setFormError] = useState(null);

  // Settings State
  const [orgData, setOrgData] = useState({ name: '', timezone: 'Asia/Karachi' });
  const [settings, setSettings] = useState({
    reminder_windows_days: { high: [3, 2, 1, 0], medium: [2, 1, 0], low: [1, 0] },
    escalation_days_overdue: {
      high:   { manager: 2, executive: 5 },
      medium: { manager: 4, executive: 8 },
      low:    { manager: 7, executive: 14 },
    },
    working_hours: { start: '09:00', end: '18:00' },
    working_days: [1, 2, 3, 4, 5],
    notification_channels: ['email'],
    webhook_urls: {
      meeting_uploaded: '',
      meeting_needs_review: '',
      tasks_approved: '',
      task_completed: '',
      task_blocked: '',
    },
  });

  // API Key State
  const [apiKeyMasked, setApiKeyMasked] = useState('••••••••••••');
  const [newPlainTextKey, setNewPlainTextKey] = useState(null);
  const [regeneratingKey, setRegeneratingKey] = useState(false);
  const [copiedKey, setCopiedKey] = useState(false);

  // Users State
  const [usersList, setUsersList] = useState([]);
  const [showAddUserModal, setShowAddUserModal] = useState(false);
  const [editingUser, setEditingUser] = useState(null);
  const [userFormData, setUserFormData] = useState({
    name: '',
    email: '',
    password: '',
    role: 'employee',
    manager_id: '',
    phone: '',
    status: 'active',
  });
  const [savingUser, setSavingUser] = useState(false);
  const [userModalError, setUserModalError] = useState(null);

  // Webhooks Log State
  const [webhookDeliveries, setWebhookDeliveries] = useState([]);
  const [selectedPayload, setSelectedPayload] = useState(null);

  const showToast = (msg) => {
    setToastMessage(msg);
    setTimeout(() => setToastMessage(null), 4500);
  };

  // Load Settings, Users, Webhooks
  const loadData = useCallback(async () => {
    setLoading(true);
    setFormError(null);
    try {
      const [settingsRes, usersRes, webhooksRes] = await Promise.all([
        adminApi.getSettings(),
        adminApi.getUsers(),
        adminApi.getWebhooks(50),
      ]);

      if (settingsRes.data) {
        setOrgData(settingsRes.data.organization || { name: 'Demo Company', timezone: 'Asia/Karachi' });
        if (settingsRes.data.settings) {
          setSettings(prev => ({
            ...prev,
            ...settingsRes.data.settings,
            webhook_urls: {
              ...prev.webhook_urls,
              ...(settingsRes.data.settings.webhook_urls || {}),
            },
          }));
        }
        if (settingsRes.data.api_key) {
          setApiKeyMasked(settingsRes.data.api_key.masked || '••••••••••••');
        }
      }

      if (usersRes.data?.users) {
        setUsersList(usersRes.data.users);
      }

      if (webhooksRes.data?.deliveries) {
        setWebhookDeliveries(webhooksRes.data.deliveries);
      }
    } catch (err) {
      console.error('Failed to load admin data:', err);
      setFormError(err?.apiError?.message || 'Failed to load administration settings.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadData();
  }, [loadData]);

  // Handle Policy & Settings Form Submission
  const handleSaveSettings = async (e) => {
    e.preventDefault();
    setSavingSettings(true);
    setFormError(null);

    // Frontend validation: Manager < Executive and both > 0
    const priorities = ['high', 'medium', 'low'];
    for (const pri of priorities) {
      const mgr = parseInt(settings.escalation_days_overdue[pri]?.manager, 10);
      const exec = parseInt(settings.escalation_days_overdue[pri]?.executive, 10);

      if (isNaN(mgr) || mgr <= 0 || isNaN(exec) || exec <= 0) {
        setFormError(`Escalation thresholds for ${pri} priority must be positive numbers greater than 0.`);
        setSavingSettings(false);
        return;
      }

      if (mgr >= exec) {
        setFormError(
          `For ${pri} priority, the manager threshold (${mgr} days) must be less than the executive threshold (${exec} days). The manager must be alerted before escalating to executive leadership.`
        );
        setSavingSettings(false);
        return;
      }
    }

    try {
      const payload = {
        name: orgData.name,
        timezone: orgData.timezone,
        settings: {
          reminder_windows_days: settings.reminder_windows_days,
          escalation_days_overdue: {
            high: {
              manager: parseInt(settings.escalation_days_overdue.high.manager, 10),
              executive: parseInt(settings.escalation_days_overdue.high.executive, 10),
            },
            medium: {
              manager: parseInt(settings.escalation_days_overdue.medium.manager, 10),
              executive: parseInt(settings.escalation_days_overdue.medium.executive, 10),
            },
            low: {
              manager: parseInt(settings.escalation_days_overdue.low.manager, 10),
              executive: parseInt(settings.escalation_days_overdue.low.executive, 10),
            },
          },
          working_hours: settings.working_hours,
          working_days: settings.working_days,
          notification_channels: settings.notification_channels,
          webhook_urls: settings.webhook_urls,
        },
      };

      const res = await adminApi.updateSettings(payload);
      showToast('Organization policy and settings saved successfully.');
      if (res.data?.organization) setOrgData(res.data.organization);
      if (res.data?.settings) setSettings(res.data.settings);
    } catch (err) {
      console.error('Failed to update settings:', err);
      setFormError(err?.apiError?.message || 'Validation failed. Please check your inputs.');
    } finally {
      setSavingSettings(false);
    }
  };

  // Handle Regenerate API Key
  const handleRegenerateApiKey = async () => {
    if (!window.confirm('Regenerate static n8n API Key? Any external automation workflows using the old key will immediately stop working until updated.')) {
      return;
    }

    setRegeneratingKey(true);
    try {
      const res = await adminApi.regenerateApiKey();
      setNewPlainTextKey(res.data.api_key);
      setApiKeyMasked(res.data.masked);
      showToast('New API key generated. Please copy it immediately.');
    } catch (err) {
      console.error('Failed to regenerate key:', err);
      showToast(err?.apiError?.message || 'Failed to regenerate API key.');
    } finally {
      setRegeneratingKey(false);
    }
  };

  const handleCopyKey = () => {
    if (!newPlainTextKey) return;
    navigator.clipboard.writeText(newPlainTextKey);
    setCopiedKey(true);
    setTimeout(() => setCopiedKey(false), 3000);
  };

  // Handle Resend Webhook
  const handleResendWebhook = async (id) => {
    setResendingWebhookId(id);
    try {
      await adminApi.resendWebhook(id);
      showToast(`Webhook #${id} queued for redelivery.`);
      // Refresh webhooks log
      const res = await adminApi.getWebhooks(50);
      if (res.data?.deliveries) setWebhookDeliveries(res.data.deliveries);
    } catch (err) {
      console.error('Failed to resend webhook:', err);
      showToast(err?.apiError?.message || 'Failed to resend webhook.');
    } finally {
      setResendingWebhookId(null);
    }
  };

  // Handle User Modal Form
  const openAddUserModal = () => {
    setUserFormData({
      name: '',
      email: '',
      password: '',
      role: 'employee',
      manager_id: '',
      phone: '',
      status: 'active',
    });
    setUserModalError(null);
    setEditingUser(null);
    setShowAddUserModal(true);
  };

  const openEditUserModal = (u) => {
    setEditingUser(u);
    setUserFormData({
      name: u.name,
      email: u.email,
      password: '',
      role: u.role,
      manager_id: u.manager_id || '',
      phone: u.phone || '',
      status: u.status || 'active',
    });
    setUserModalError(null);
    setShowAddUserModal(true);
  };

  const handleSaveUser = async (e) => {
    e.preventDefault();
    setSavingUser(true);
    setUserModalError(null);

    try {
      const payload = {
        name: userFormData.name,
        email: userFormData.email,
        role: userFormData.role,
        manager_id: userFormData.manager_id ? parseInt(userFormData.manager_id, 10) : null,
        phone: userFormData.phone || null,
        status: userFormData.status,
      };

      if (userFormData.password) {
        payload.password = userFormData.password;
      }

      if (editingUser) {
        await adminApi.updateUser(editingUser.id, payload);
        showToast(`User ${userFormData.name} updated successfully.`);
      } else {
        if (!userFormData.password) {
          setUserModalError('Password is required for new users.');
          setSavingUser(false);
          return;
        }
        await adminApi.createUser(payload);
        showToast(`User ${userFormData.name} created successfully.`);
      }

      setShowAddUserModal(false);
      // Reload users list
      const res = await adminApi.getUsers();
      if (res.data?.users) setUsersList(res.data.users);
    } catch (err) {
      console.error('Failed to save user:', err);
      setUserModalError(err?.apiError?.message || 'Failed to save user.');
    } finally {
      setSavingUser(false);
    }
  };

  return (
    <div className="space-y-6 pb-16">
      {/* Top Banner */}
      <div className="p-6 rounded-2xl glass-panel border border-slate-800/80 bg-gradient-to-r from-slate-900 via-indigo-950/20 to-slate-900 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <span className="text-xs font-semibold uppercase tracking-wider text-indigo-400">
              Administration Center
            </span>
            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-indigo-500/10 text-indigo-300 border border-indigo-500/20">
              System Policy & Tenant Config
            </span>
          </div>
          <h1 className="text-2xl font-bold text-white mt-1">Admin Configuration</h1>
          <p className="text-sm text-slate-400 mt-1">
            Global escalation thresholds, working hours, user roster, API keys, and outbound delivery monitoring.
          </p>
        </div>

        <div className="flex items-center gap-2 self-start md:self-auto">
          <button
            onClick={loadData}
            disabled={loading}
            className="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-medium text-slate-300 hover:text-white bg-slate-800/80 hover:bg-slate-700/80 border border-slate-700/60 transition-all disabled:opacity-50"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin text-indigo-400' : ''}`} />
            Refresh
          </button>
        </div>
      </div>

      {/* Toast Notification */}
      {toastMessage && (
        <div className="fixed bottom-6 right-6 z-50 p-4 rounded-xl bg-slate-900 border border-emerald-500/40 text-emerald-300 shadow-2xl flex items-center gap-3 animate-in fade-in slide-in-from-bottom-3">
          <CheckCircle2 className="w-5 h-5 text-emerald-400 shrink-0" />
          <span className="text-sm font-medium">{toastMessage}</span>
        </div>
      )}

      {/* Error Banner */}
      {formError && (
        <div className="p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-sm flex items-center gap-3">
          <AlertOctagon className="w-5 h-5 shrink-0 text-rose-400" />
          <span>{formError}</span>
        </div>
      )}

      {/* Pending Reviews Section Link (Preserved from spec) */}
      <div className="p-4 rounded-xl glass-card border border-indigo-500/30 bg-gradient-to-r from-indigo-950/30 via-slate-900 to-indigo-950/20 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="w-8 h-8 rounded-lg bg-indigo-600/20 border border-indigo-500/30 text-indigo-400 flex items-center justify-center shrink-0">
            <FileCheck2 className="w-4 h-4" />
          </div>
          <div>
            <h2 className="text-sm font-bold text-white">Meeting Reviews & Action Extraction</h2>
            <p className="text-xs text-slate-400">Review AI-extracted commitments and assign ambiguous owners.</p>
          </div>
        </div>
        <Link
          to="/meetings/new"
          className="px-3.5 py-1.5 rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white text-xs font-bold flex items-center justify-center gap-1.5 shadow-lg shadow-indigo-500/20 shrink-0 transition-all"
        >
          <span>Open Review Screen</span>
          <ArrowRight className="w-3.5 h-3.5" />
        </Link>
      </div>

      {/* Navigation Tabs */}
      <div className="flex flex-wrap items-center gap-2 border-b border-slate-800 pb-3">
        <button
          onClick={() => setActiveTab('policy')}
          className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold transition-all ${
            activeTab === 'policy'
              ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-500/20'
              : 'text-slate-400 hover:text-white hover:bg-slate-800/60'
          }`}
        >
          <Sliders className="w-4 h-4" />
          <span>Org Policy & Escalation</span>
        </button>

        <button
          onClick={() => setActiveTab('api_webhooks')}
          className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold transition-all ${
            activeTab === 'api_webhooks'
              ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-500/20'
              : 'text-slate-400 hover:text-white hover:bg-slate-800/60'
          }`}
        >
          <Key className="w-4 h-4" />
          <span>API Key & Webhook URLs</span>
        </button>

        <button
          onClick={() => setActiveTab('users')}
          className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold transition-all ${
            activeTab === 'users'
              ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-500/20'
              : 'text-slate-400 hover:text-white hover:bg-slate-800/60'
          }`}
        >
          <Users className="w-4 h-4" />
          <span>Users & Reporting Lines ({usersList.length})</span>
        </button>

        <button
          onClick={() => setActiveTab('webhooks')}
          className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold transition-all ${
            activeTab === 'webhooks'
              ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-500/20'
              : 'text-slate-400 hover:text-white hover:bg-slate-800/60'
          }`}
        >
          <Webhook className="w-4 h-4" />
          <span>Webhook Delivery Log ({webhookDeliveries.length})</span>
        </button>
      </div>

      {loading && !settings ? (
        <div className="py-20 flex flex-col items-center justify-center text-slate-400 space-y-3">
          <Loader2 className="w-8 h-8 animate-spin text-indigo-500" />
          <p className="text-sm">Loading admin configuration...</p>
        </div>
      ) : (
        <>
          {/* ================================================================= */}
          {/* TAB 1: ORGANIZATION POLICY & ESCALATION SETTINGS                  */}
          {/* ================================================================= */}
          {activeTab === 'policy' && (
            <form onSubmit={handleSaveSettings} className="space-y-6">
              {/* Organization Metadata */}
              <div className="p-6 rounded-2xl glass-panel border border-slate-800/80 space-y-4">
                <h3 className="text-sm font-bold text-white flex items-center gap-2">
                  <Settings className="w-4 h-4 text-indigo-400" />
                  Organization Details
                </h3>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-xs font-medium text-slate-400 mb-1">Organization Name</label>
                    <input
                      type="text"
                      value={orgData.name}
                      onChange={(e) => setOrgData({ ...orgData, name: e.target.value })}
                      className="w-full px-3 py-2 rounded-xl bg-slate-900/80 border border-slate-700/80 text-white text-sm focus:outline-none focus:border-indigo-500"
                      required
                    />
                  </div>

                  <div>
                    <label className="block text-xs font-medium text-slate-400 mb-1">Timezone (Section 8 Rule)</label>
                    <select
                      value={orgData.timezone}
                      onChange={(e) => setOrgData({ ...orgData, timezone: e.target.value })}
                      className="w-full px-3 py-2 rounded-xl bg-slate-900/80 border border-slate-700/80 text-white text-sm focus:outline-none focus:border-indigo-500"
                    >
                      <option value="Asia/Karachi">Asia/Karachi (UTC+5)</option>
                      <option value="UTC">UTC</option>
                      <option value="America/New_York">America/New_York (EST)</option>
                      <option value="America/Los_Angeles">America/Los_Angeles (PST)</option>
                      <option value="Europe/London">Europe/London (GMT/BST)</option>
                      <option value="Asia/Dubai">Asia/Dubai (UTC+4)</option>
                      <option value="Asia/Singapore">Asia/Singapore (UTC+8)</option>
                    </select>
                    <p className="text-[11px] text-slate-500 mt-1">Deadlines are evaluated at end-of-day in this timezone.</p>
                  </div>
                </div>
              </div>

              {/* ESCALATION THRESHOLDS FORM */}
              <div className="p-6 rounded-2xl glass-panel border border-slate-800/80 space-y-5">
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-2 border-b border-slate-800 pb-3">
                  <div>
                    <h3 className="text-sm font-bold text-white flex items-center gap-2">
                      <ShieldAlert className="w-4 h-4 text-amber-400" />
                      Escalation Thresholds (Days Overdue)
                    </h3>
                    <p className="text-xs text-slate-400 mt-0.5">
                      How many days a task must sit overdue before escalating to the direct manager and executive leadership.
                    </p>
                  </div>
                  <span className="text-[11px] text-amber-400 bg-amber-500/10 border border-amber-500/20 px-2.5 py-1 rounded-full font-medium self-start md:self-auto">
                    Rule: Manager &lt; Executive
                  </span>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                  {/* High Priority */}
                  <div className="p-4 rounded-xl bg-slate-900/60 border border-rose-500/20 space-y-3">
                    <div className="flex items-center justify-between">
                      <span className="text-xs font-bold uppercase text-rose-400">High Priority</span>
                      <span className="text-[10px] px-2 py-0.5 rounded bg-rose-500/10 text-rose-300 border border-rose-500/30">Urgent</span>
                    </div>

                    <div className="space-y-2">
                      <div>
                        <label className="block text-[11px] text-slate-400">Manager Alert (Days Overdue)</label>
                        <input
                          type="number"
                          min="1"
                          value={settings.escalation_days_overdue.high.manager}
                          onChange={(e) => setSettings({
                            ...settings,
                            escalation_days_overdue: {
                              ...settings.escalation_days_overdue,
                              high: { ...settings.escalation_days_overdue.high, manager: e.target.value }
                            }
                          })}
                          className="w-full mt-1 px-3 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-white text-sm focus:border-rose-500 focus:outline-none"
                          required
                        />
                      </div>

                      <div>
                        <label className="block text-[11px] text-slate-400">Executive Alert (Days Overdue)</label>
                        <input
                          type="number"
                          min="2"
                          value={settings.escalation_days_overdue.high.executive}
                          onChange={(e) => setSettings({
                            ...settings,
                            escalation_days_overdue: {
                              ...settings.escalation_days_overdue,
                              high: { ...settings.escalation_days_overdue.high, executive: e.target.value }
                            }
                          })}
                          className="w-full mt-1 px-3 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-white text-sm focus:border-rose-500 focus:outline-none"
                          required
                        />
                      </div>
                    </div>
                  </div>

                  {/* Medium Priority */}
                  <div className="p-4 rounded-xl bg-slate-900/60 border border-amber-500/20 space-y-3">
                    <div className="flex items-center justify-between">
                      <span className="text-xs font-bold uppercase text-amber-400">Medium Priority</span>
                      <span className="text-[10px] px-2 py-0.5 rounded bg-amber-500/10 text-amber-300 border border-amber-500/30">Standard</span>
                    </div>

                    <div className="space-y-2">
                      <div>
                        <label className="block text-[11px] text-slate-400">Manager Alert (Days Overdue)</label>
                        <input
                          type="number"
                          min="1"
                          value={settings.escalation_days_overdue.medium.manager}
                          onChange={(e) => setSettings({
                            ...settings,
                            escalation_days_overdue: {
                              ...settings.escalation_days_overdue,
                              medium: { ...settings.escalation_days_overdue.medium, manager: e.target.value }
                            }
                          })}
                          className="w-full mt-1 px-3 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-white text-sm focus:border-amber-500 focus:outline-none"
                          required
                        />
                      </div>

                      <div>
                        <label className="block text-[11px] text-slate-400">Executive Alert (Days Overdue)</label>
                        <input
                          type="number"
                          min="2"
                          value={settings.escalation_days_overdue.medium.executive}
                          onChange={(e) => setSettings({
                            ...settings,
                            escalation_days_overdue: {
                              ...settings.escalation_days_overdue,
                              medium: { ...settings.escalation_days_overdue.medium, executive: e.target.value }
                            }
                          })}
                          className="w-full mt-1 px-3 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-white text-sm focus:border-amber-500 focus:outline-none"
                          required
                        />
                      </div>
                    </div>
                  </div>

                  {/* Low Priority */}
                  <div className="p-4 rounded-xl bg-slate-900/60 border border-sky-500/20 space-y-3">
                    <div className="flex items-center justify-between">
                      <span className="text-xs font-bold uppercase text-sky-400">Low Priority</span>
                      <span className="text-[10px] px-2 py-0.5 rounded bg-sky-500/10 text-sky-300 border border-sky-500/30">Routine</span>
                    </div>

                    <div className="space-y-2">
                      <div>
                        <label className="block text-[11px] text-slate-400">Manager Alert (Days Overdue)</label>
                        <input
                          type="number"
                          min="1"
                          value={settings.escalation_days_overdue.low.manager}
                          onChange={(e) => setSettings({
                            ...settings,
                            escalation_days_overdue: {
                              ...settings.escalation_days_overdue,
                              low: { ...settings.escalation_days_overdue.low, manager: e.target.value }
                            }
                          })}
                          className="w-full mt-1 px-3 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-white text-sm focus:border-sky-500 focus:outline-none"
                          required
                        />
                      </div>

                      <div>
                        <label className="block text-[11px] text-slate-400">Executive Alert (Days Overdue)</label>
                        <input
                          type="number"
                          min="2"
                          value={settings.escalation_days_overdue.low.executive}
                          onChange={(e) => setSettings({
                            ...settings,
                            escalation_days_overdue: {
                              ...settings.escalation_days_overdue,
                              low: { ...settings.escalation_days_overdue.low, executive: e.target.value }
                            }
                          })}
                          className="w-full mt-1 px-3 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-white text-sm focus:border-sky-500 focus:outline-none"
                          required
                        />
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              {/* WORKING HOURS & DAYS */}
              <div className="p-6 rounded-2xl glass-panel border border-slate-800/80 space-y-4">
                <h3 className="text-sm font-bold text-white flex items-center gap-2">
                  <Clock className="w-4 h-4 text-indigo-400" />
                  Working Hours & Business Days
                </h3>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-xs font-medium text-slate-400 mb-1">Working Hours (Start / End)</label>
                    <div className="flex items-center gap-2">
                      <input
                        type="time"
                        value={settings.working_hours.start}
                        onChange={(e) => setSettings({
                          ...settings,
                          working_hours: { ...settings.working_hours, start: e.target.value }
                        })}
                        className="px-3 py-2 rounded-xl bg-slate-900/80 border border-slate-700 text-white text-sm focus:outline-none focus:border-indigo-500"
                        required
                      />
                      <span className="text-slate-500 text-xs">to</span>
                      <input
                        type="time"
                        value={settings.working_hours.end}
                        onChange={(e) => setSettings({
                          ...settings,
                          working_hours: { ...settings.working_hours, end: e.target.value }
                        })}
                        className="px-3 py-2 rounded-xl bg-slate-900/80 border border-slate-700 text-white text-sm focus:outline-none focus:border-indigo-500"
                        required
                      />
                    </div>
                  </div>

                  <div>
                    <label className="block text-xs font-medium text-slate-400 mb-1.5">Active Working Days</label>
                    <div className="flex flex-wrap items-center gap-2">
                      {DAY_NAMES.map((d) => {
                        const isChecked = settings.working_days.includes(d.id);
                        return (
                          <button
                            type="button"
                            key={d.id}
                            onClick={() => {
                              const newDays = isChecked
                                ? settings.working_days.filter(x => x !== d.id)
                                : [...settings.working_days, d.id].sort();
                              setSettings({ ...settings, working_days: newDays });
                            }}
                            className={`px-3 py-1 rounded-lg text-xs font-medium border transition-all ${
                              isChecked
                                ? 'bg-indigo-600/30 text-indigo-300 border-indigo-500/50'
                                : 'bg-slate-900 text-slate-500 border-slate-800 hover:border-slate-700'
                            }`}
                          >
                            {d.name.slice(0, 3)}
                          </button>
                        );
                      })}
                    </div>
                  </div>
                </div>
              </div>

              {/* SAVE BUTTON */}
              <div className="flex justify-end pt-2">
                <button
                  type="submit"
                  disabled={savingSettings}
                  className="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-semibold text-xs shadow-lg shadow-indigo-500/20 transition-all flex items-center gap-2 disabled:opacity-50"
                >
                  {savingSettings ? <Loader2 className="w-4 h-4 animate-spin" /> : <Check className="w-4 h-4" />}
                  Save Policy Configuration
                </button>
              </div>
            </form>
          )}

          {/* ================================================================= */}
          {/* TAB 2: API KEY & WEBHOOK CONFIGURATION                            */}
          {/* ================================================================= */}
          {activeTab === 'api_webhooks' && (
            <div className="space-y-6">
              {/* API Key Panel (Masked by Default) */}
              <div className="p-6 rounded-2xl glass-panel border border-slate-800/80 space-y-4">
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-3 border-b border-slate-800 pb-3">
                  <div>
                    <h3 className="text-sm font-bold text-white flex items-center gap-2">
                      <Key className="w-4 h-4 text-indigo-400" />
                      Automation Machine API Key (X-API-Key)
                    </h3>
                    <p className="text-xs text-slate-400 mt-0.5">
                      Used by the n8n automation layer for authenticating to /api/v1/ endpoints.
                    </p>
                  </div>
                  <button
                    type="button"
                    onClick={handleRegenerateApiKey}
                    disabled={regeneratingKey}
                    className="px-3.5 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-amber-300 border border-amber-500/30 text-xs font-semibold flex items-center gap-1.5 transition-all self-start md:self-auto disabled:opacity-50"
                  >
                    {regeneratingKey ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <RefreshCw className="w-3.5 h-3.5" />}
                    Regenerate Key
                  </button>
                </div>

                <div className="space-y-3">
                  <div>
                    <label className="block text-xs font-medium text-slate-400 mb-1">Current Active Key</label>
                    <div className="flex items-center gap-3 min-w-0">
                      <div className="flex-1 min-w-0 px-4 py-2.5 rounded-xl bg-slate-950 border border-slate-800 font-mono text-xs text-slate-300 select-all tracking-wider overflow-hidden">
                        <span className="block truncate">{apiKeyMasked}</span>
                      </div>
                      <span className="shrink-0 text-[11px] text-slate-500 px-2 py-1 bg-slate-900 rounded border border-slate-800 whitespace-nowrap">
                        Masked for security
                      </span>
                    </div>
                  </div>

                  {/* Plaintext Banner when newly generated */}
                  {newPlainTextKey && (
                    <div className="p-4 rounded-xl bg-emerald-950/30 border border-emerald-500/40 space-y-2">
                      <div className="flex items-center justify-between">
                        <span className="text-xs font-bold text-emerald-300 flex items-center gap-1.5">
                          <CheckCircle2 className="w-4 h-4 text-emerald-400" />
                          New API Key Generated Successfully
                        </span>
                        <button
                          onClick={handleCopyKey}
                          className="px-3 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold flex items-center gap-1.5 transition-all"
                        >
                          {copiedKey ? <Check className="w-3.5 h-3.5" /> : <Copy className="w-3.5 h-3.5" />}
                          {copiedKey ? 'Copied!' : 'Copy Key'}
                        </button>
                      </div>
                      <p className="text-xs font-mono text-emerald-200 bg-slate-950/80 p-2.5 rounded-lg border border-emerald-500/20 select-all break-all">
                        {newPlainTextKey}
                      </p>
                      <p className="text-[11px] text-emerald-400/80">
                        Important: Copy this key immediately. For security, it will not be displayed in plaintext again upon refresh.
                      </p>
                    </div>
                  )}
                </div>
              </div>

              {/* Webhook URLs Form */}
              <form onSubmit={handleSaveSettings} className="p-6 rounded-2xl glass-panel border border-slate-800/80 space-y-4">
                <div className="border-b border-slate-800 pb-3">
                  <h3 className="text-sm font-bold text-white flex items-center gap-2">
                    <Webhook className="w-4 h-4 text-emerald-400" />
                    Outbound Webhook Endpoints
                  </h3>
                  <p className="text-xs text-slate-400 mt-0.5">
                    Configure target URLs for outbound CRM events. All payloads are signed with HMAC-SHA256 in X-Signature.
                  </p>
                </div>

                <div className="space-y-3">
                  <div>
                    <label className="block text-xs font-medium text-slate-400 mb-1">meeting.uploaded</label>
                    <input
                      type="url"
                      value={settings.webhook_urls.meeting_uploaded || ''}
                      onChange={(e) => setSettings({
                        ...settings,
                        webhook_urls: { ...settings.webhook_urls, meeting_uploaded: e.target.value }
                      })}
                      placeholder="https://n8n.example.com/webhook/meeting-uploaded"
                      className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white text-xs font-mono focus:border-emerald-500 focus:outline-none"
                    />
                  </div>

                  <div>
                    <label className="block text-xs font-medium text-slate-400 mb-1">meeting.needs_review</label>
                    <input
                      type="url"
                      value={settings.webhook_urls.meeting_needs_review || ''}
                      onChange={(e) => setSettings({
                        ...settings,
                        webhook_urls: { ...settings.webhook_urls, meeting_needs_review: e.target.value }
                      })}
                      placeholder="https://n8n.example.com/webhook/meeting-needs-review"
                      className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white text-xs font-mono focus:border-emerald-500 focus:outline-none"
                    />
                  </div>

                  <div>
                    <label className="block text-xs font-medium text-slate-400 mb-1">tasks.approved</label>
                    <input
                      type="url"
                      value={settings.webhook_urls.tasks_approved || ''}
                      onChange={(e) => setSettings({
                        ...settings,
                        webhook_urls: { ...settings.webhook_urls, tasks_approved: e.target.value }
                      })}
                      placeholder="https://n8n.example.com/webhook/tasks-approved"
                      className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white text-xs font-mono focus:border-emerald-500 focus:outline-none"
                    />
                  </div>

                  <div>
                    <label className="block text-xs font-medium text-slate-400 mb-1">task.completed</label>
                    <input
                      type="url"
                      value={settings.webhook_urls.task_completed || ''}
                      onChange={(e) => setSettings({
                        ...settings,
                        webhook_urls: { ...settings.webhook_urls, task_completed: e.target.value }
                      })}
                      placeholder="https://n8n.example.com/webhook/task-completed"
                      className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white text-xs font-mono focus:border-emerald-500 focus:outline-none"
                    />
                  </div>

                  <div>
                    <label className="block text-xs font-medium text-slate-400 mb-1">task.blocked</label>
                    <input
                      type="url"
                      value={settings.webhook_urls.task_blocked || ''}
                      onChange={(e) => setSettings({
                        ...settings,
                        webhook_urls: { ...settings.webhook_urls, task_blocked: e.target.value }
                      })}
                      placeholder="https://n8n.example.com/webhook/task-blocked"
                      className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white text-xs font-mono focus:border-emerald-500 focus:outline-none"
                    />
                  </div>
                </div>

                <div className="flex justify-end pt-3">
                  <button
                    type="submit"
                    disabled={savingSettings}
                    className="px-5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs shadow-lg shadow-emerald-500/20 transition-all flex items-center gap-1.5 disabled:opacity-50"
                  >
                    {savingSettings ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Check className="w-3.5 h-3.5" />}
                    Save Webhook Endpoints
                  </button>
                </div>
              </form>
            </div>
          )}

          {/* ================================================================= */}
          {/* TAB 3: USER MANAGEMENT & REPORTING LINES                         */}
          {/* ================================================================= */}
          {activeTab === 'users' && (
            <div className="p-6 rounded-2xl glass-panel border border-slate-800/80 space-y-4">
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-800 pb-4">
                <div>
                  <h3 className="text-base font-bold text-white flex items-center gap-2">
                    <Users className="w-4 h-4 text-indigo-400" />
                    Organization Users &amp; Hierarchy
                  </h3>
                  <p className="text-xs text-slate-400 mt-0.5">
                    Manage tenant roles, permissions, and direct report lines.
                  </p>
                </div>

                <button
                  onClick={openAddUserModal}
                  className="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold flex items-center gap-1.5 shadow-lg shadow-indigo-500/20 transition-all self-start sm:self-auto"
                >
                  <Plus className="w-3.5 h-3.5" />
                  Add User
                </button>
              </div>

              {/* Users Table */}
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead className="border-b border-slate-800 text-slate-400 uppercase text-[10px] tracking-wider">
                    <tr>
                      <th className="py-3 px-3">Name / Email</th>
                      <th className="py-3 px-3">Role</th>
                      <th className="py-3 px-3">Manager</th>
                      <th className="py-3 px-3">Direct Reports</th>
                      <th className="py-3 px-3">Status</th>
                      <th className="py-3 px-3 text-right">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800/60">
                    {usersList.map((u) => {
                      const roleClass = ROLE_BADGES[u.role] || ROLE_BADGES.employee;
                      return (
                        <tr key={u.id} className="hover:bg-slate-800/30 transition-colors">
                          <td className="py-3.5 px-3">
                            <div className="font-semibold text-white text-sm">{u.name}</div>
                            <div className="text-slate-400 font-mono text-[11px]">{u.email}</div>
                          </td>
                          <td className="py-3.5 px-3">
                            <span className={`px-2 py-0.5 rounded-full text-[10px] font-bold uppercase border ${roleClass}`}>
                              {u.role}
                            </span>
                          </td>
                          <td className="py-3.5 px-3">
                            {u.manager ? (
                              <span className="text-slate-200 font-medium">{u.manager.name}</span>
                            ) : (
                              <span className="text-slate-500 italic">No Manager</span>
                            )}
                          </td>
                          <td className="py-3.5 px-3">
                            <span className="text-slate-300 font-medium">
                              {u.direct_reports_count} {u.direct_reports_count === 1 ? 'report' : 'reports'}
                            </span>
                          </td>
                          <td className="py-3.5 px-3">
                            <span className={`px-2 py-0.5 rounded-full text-[10px] font-semibold ${
                              u.status === 'active'
                                ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20'
                                : 'bg-slate-800 text-slate-400 border border-slate-700'
                            }`}>
                              {u.status}
                            </span>
                          </td>
                          <td className="py-3.5 px-3 text-right">
                            <button
                              onClick={() => openEditUserModal(u)}
                              className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
                              title="Edit user"
                            >
                              <Edit2 className="w-3.5 h-3.5" />
                            </button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* ================================================================= */}
          {/* TAB 4: WEBHOOK DELIVERY LOG                                       */}
          {/* ================================================================= */}
          {activeTab === 'webhooks' && (
            <div className="p-6 rounded-2xl glass-panel border border-slate-800/80 space-y-4">
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-800 pb-4">
                <div>
                  <h3 className="text-base font-bold text-white flex items-center gap-2">
                    <Webhook className="w-4 h-4 text-emerald-400" />
                    Webhook Delivery Audit Log
                  </h3>
                  <p className="text-xs text-slate-400 mt-0.5">
                    Live record of every outbound event attempt. Use the Resend button to retry delivery.
                  </p>
                </div>
              </div>

              {webhookDeliveries.length === 0 ? (
                <div className="py-12 text-center text-slate-500">
                  <p className="text-sm">No webhook deliveries recorded yet.</p>
                </div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-left text-xs">
                    <thead className="border-b border-slate-800 text-slate-400 uppercase text-[10px] tracking-wider">
                      <tr>
                        <th className="py-3 px-3">ID / Event</th>
                        <th className="py-3 px-3">Target Endpoint</th>
                        <th className="py-3 px-3">Status</th>
                        <th className="py-3 px-3">Attempts</th>
                        <th className="py-3 px-3">Delivered At</th>
                        <th className="py-3 px-3 text-right">Actions</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-800/60">
                      {webhookDeliveries.map((delivery) => {
                        const isSuccess = delivery.last_status_code >= 200 && delivery.last_status_code < 300;
                        const isPending = delivery.last_status_code === null;

                        return (
                          <tr key={delivery.id} className="hover:bg-slate-800/30 transition-colors">
                            <td className="py-3 px-3">
                              <div className="font-mono font-bold text-white text-xs">#{delivery.id}</div>
                              <span className="font-semibold text-emerald-400 text-[11px]">{delivery.event_type}</span>
                            </td>
                            <td className="py-3 px-3">
                              <div className="font-mono text-slate-300 text-[11px] truncate max-w-xs" title={delivery.target_url}>
                                {delivery.target_url || 'No URL configured'}
                              </div>
                              {delivery.last_error && (
                                <div className="text-rose-400 text-[10px] truncate max-w-xs mt-0.5">
                                  {delivery.last_error}
                                </div>
                              )}
                            </td>
                            <td className="py-3 px-3">
                              {isPending ? (
                                <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/10 text-amber-300 border border-amber-500/30">
                                  Queued / In Flight
                                </span>
                              ) : isSuccess ? (
                                <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                                  {delivery.last_status_code} OK
                                </span>
                              ) : (
                                <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-500/10 text-rose-400 border border-rose-500/30">
                                  HTTP {delivery.last_status_code || 'Error'}
                                </span>
                              )}
                            </td>
                            <td className="py-3 px-3 text-slate-300 font-medium">
                              {delivery.attempt_count} / 5
                            </td>
                            <td className="py-3 px-3 text-slate-400 text-[11px]">
                              {delivery.delivered_at
                                ? new Date(delivery.delivered_at).toLocaleString()
                                : new Date(delivery.created_at).toLocaleString()}
                            </td>
                            <td className="py-3 px-3 text-right space-x-2">
                              <button
                                onClick={() => setSelectedPayload(delivery)}
                                className="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium transition-colors"
                              >
                                View Payload
                              </button>

                              <button
                                onClick={() => handleResendWebhook(delivery.id)}
                                disabled={resendingWebhookId === delivery.id}
                                className="px-2.5 py-1 rounded-lg bg-indigo-600/20 hover:bg-indigo-600/40 text-indigo-300 border border-indigo-500/30 text-xs font-semibold inline-flex items-center gap-1 transition-colors disabled:opacity-50"
                              >
                                {resendingWebhookId === delivery.id ? (
                                  <Loader2 className="w-3 h-3 animate-spin" />
                                ) : (
                                  <Send className="w-3 h-3" />
                                )}
                                Resend
                              </button>
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          )}
        </>
      )}

      {/* =================================================================== */}
      {/* USER MODAL (ADD / EDIT)                                             */}
      {/* =================================================================== */}
      {showAddUserModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
          <div className="w-full max-w-md rounded-2xl bg-slate-900 border border-slate-700/80 shadow-2xl overflow-hidden animate-in fade-in zoom-in-95">
            <div className="flex items-center justify-between px-6 py-4 border-b border-slate-800">
              <h2 className="font-semibold text-white text-sm">
                {editingUser ? `Edit User: ${editingUser.name}` : 'Add New Organization User'}
              </h2>
              <button
                onClick={() => setShowAddUserModal(false)}
                className="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form onSubmit={handleSaveUser} className="p-6 space-y-4">
              {userModalError && (
                <div className="p-3 rounded-lg bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs flex items-center gap-2">
                  <AlertTriangle className="w-4 h-4 shrink-0" />
                  <span>{userModalError}</span>
                </div>
              )}

              <div>
                <label className="block text-xs font-medium text-slate-300 mb-1">Full Name</label>
                <input
                  type="text"
                  value={userFormData.name}
                  onChange={(e) => setUserFormData({ ...userFormData, name: e.target.value })}
                  className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white text-xs focus:border-indigo-500 focus:outline-none"
                  required
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-300 mb-1">Email Address</label>
                <input
                  type="email"
                  value={userFormData.email}
                  onChange={(e) => setUserFormData({ ...userFormData, email: e.target.value })}
                  className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white text-xs focus:border-indigo-500 focus:outline-none"
                  required
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-300 mb-1">
                  Password {editingUser ? '(leave blank to keep existing)' : ''}
                </label>
                <input
                  type="password"
                  value={userFormData.password}
                  onChange={(e) => setUserFormData({ ...userFormData, password: e.target.value })}
                  placeholder={editingUser ? '••••••••' : 'Minimum 8 characters'}
                  className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white text-xs focus:border-indigo-500 focus:outline-none"
                  minLength={userFormData.password ? 8 : undefined}
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-medium text-slate-300 mb-1">Role</label>
                  <select
                    value={userFormData.role}
                    onChange={(e) => setUserFormData({ ...userFormData, role: e.target.value })}
                    className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white text-xs focus:border-indigo-500 focus:outline-none"
                  >
                    <option value="employee">Employee</option>
                    <option value="manager">Manager</option>
                    <option value="executive">Executive</option>
                    <option value="admin">Admin</option>
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-medium text-slate-300 mb-1">Status</label>
                  <select
                    value={userFormData.status}
                    onChange={(e) => setUserFormData({ ...userFormData, status: e.target.value })}
                    className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white text-xs focus:border-indigo-500 focus:outline-none"
                  >
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                  </select>
                </div>
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-300 mb-1">Reports to Manager</label>
                <select
                  value={userFormData.manager_id}
                  onChange={(e) => setUserFormData({ ...userFormData, manager_id: e.target.value })}
                  className="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-white text-xs focus:border-indigo-500 focus:outline-none"
                >
                  <option value="">No Manager (Root)</option>
                  {usersList
                    .filter(u => !editingUser || u.id !== editingUser.id)
                    .map(u => (
                      <option key={u.id} value={u.id}>
                        {u.name} ({u.role})
                      </option>
                    ))}
                </select>
              </div>

              <div className="flex justify-end gap-2 pt-2 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setShowAddUserModal(false)}
                  className="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-semibold"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={savingUser}
                  className="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold flex items-center gap-1.5 disabled:opacity-50"
                >
                  {savingUser && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
                  {editingUser ? 'Update User' : 'Create User'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* =================================================================== */}
      {/* PAYLOAD VIEWER MODAL                                                */}
      {/* =================================================================== */}
      {selectedPayload && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
          <div className="w-full max-w-xl rounded-2xl bg-slate-900 border border-slate-700/80 shadow-2xl overflow-hidden">
            <div className="flex items-center justify-between px-6 py-4 border-b border-slate-800">
              <div className="flex items-center gap-2">
                <Webhook className="w-4 h-4 text-emerald-400" />
                <h3 className="font-semibold text-white text-sm">
                  Webhook #{selectedPayload.id} Payload ({selectedPayload.event_type})
                </h3>
              </div>
              <button
                onClick={() => setSelectedPayload(null)}
                className="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <div className="p-6 space-y-4 max-h-[75vh] overflow-y-auto">
              <div className="text-xs text-slate-400 space-y-1">
                <div>Target URL: <span className="text-slate-200 font-mono">{selectedPayload.target_url}</span></div>
                <div>Status: <span className="text-slate-200">{selectedPayload.last_status_code || 'Pending'}</span></div>
                {selectedPayload.last_error && (
                  <div className="text-rose-400">Error: {selectedPayload.last_error}</div>
                )}
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-300 mb-1">Raw JSON Payload</label>
                <pre className="p-4 rounded-xl bg-slate-950 border border-slate-800 text-emerald-400 font-mono text-[11px] overflow-x-auto select-all">
                  {JSON.stringify(selectedPayload.payload, null, 2)}
                </pre>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
