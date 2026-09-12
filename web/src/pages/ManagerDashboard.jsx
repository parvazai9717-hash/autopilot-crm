import React, { useState, useEffect, useCallback } from 'react';
import { useAuth } from '../context/AuthContext';
import { managerApi } from '../services/api';
import {
  Users, AlertCircle, CheckCircle2, TrendingUp, Clock, AlertTriangle,
  Lock, Unlock, ShieldAlert, MessageSquare, Calendar, UserCheck,
  Flame, RefreshCw, Loader2, X, ChevronDown, ChevronUp, Search,
  ArrowRight, Check,
} from 'lucide-react';

// ─── Constants ──────────────────────────────────────────────────────────────

const REASON_LABELS = {
  waiting_for_person:   'Waiting for a person',
  waiting_for_info:     'Waiting for information',
  waiting_for_approval: 'Waiting for approval',
  technical_issue:      'Technical issue / tooling',
  unclear_requirement:  'Unclear requirement',
  other:                'Other blocker',
};

const PRIORITY_BADGES = {
  high:   'text-rose-400 bg-rose-500/10 border-rose-500/30',
  medium: 'text-amber-400 bg-amber-500/10 border-amber-500/30',
  low:    'text-sky-400  bg-sky-500/10  border-sky-500/30',
};

const STATUS_BADGES = {
  assigned:    'text-indigo-300 bg-indigo-500/10 border-indigo-500/30',
  in_progress: 'text-emerald-300 bg-emerald-500/10 border-emerald-500/30',
  blocked:     'text-orange-300 bg-orange-500/10 border-orange-500/30',
  overdue:     'text-rose-300 bg-rose-500/10 border-rose-500/30',
  escalated:   'text-amber-300 bg-amber-500/10 border-amber-500/30',
  completed:   'text-slate-400 bg-slate-800 border-slate-700',
};

const STATUS_LABELS = {
  assigned:    'Assigned',
  in_progress: 'In Progress',
  blocked:     'Blocked',
  overdue:     'Overdue',
  escalated:   'Escalated',
  completed:   'Completed',
};

// ─── Modals ─────────────────────────────────────────────────────────────────

function ReassignModal({ task, directReports, onClose, onReassign }) {
  const [selectedOwnerId, setSelectedOwnerId] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);

  // Exclude current owner from options or highlight current
  const otherReports = directReports.filter(r => r.id !== task.owner?.id);

  async function handleSubmit(e) {
    e.preventDefault();
    if (!selectedOwnerId) {
      setError('Please choose a direct report.');
      return;
    }
    setSaving(true);
    setError(null);
    try {
      await onReassign(task.id, parseInt(selectedOwnerId, 10));
      onClose();
    } catch (err) {
      setError(err?.apiError?.message ?? 'Failed to reassign task.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
      <div className="w-full max-w-md rounded-2xl bg-slate-900 border border-slate-700/80 shadow-2xl overflow-hidden">
        <div className="flex items-center justify-between px-6 py-4 border-b border-slate-800">
          <div className="flex items-center gap-2.5">
            <div className="w-8 h-8 rounded-lg bg-sky-500/20 flex items-center justify-center">
              <UserCheck className="w-4 h-4 text-sky-400" />
            </div>
            <div>
              <h2 className="font-semibold text-white text-sm">Reassign Task</h2>
              <p className="text-xs text-slate-400 truncate max-w-xs">{task.title}</p>
            </div>
          </div>
          <button onClick={onClose} className="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors">
            <X className="w-4 h-4" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="p-6 space-y-4">
          <div className="p-3 rounded-lg bg-slate-800/50 border border-slate-700/60 text-xs">
            <span className="text-slate-400">Current Assignee: </span>
            <span className="text-white font-medium">{task.owner?.name ?? 'Unassigned'}</span>
            <span className="text-slate-500 ml-1">({task.owner?.email})</span>
          </div>

          <div>
            <label className="block text-xs font-medium text-slate-300 mb-1.5">
              Assign to Direct Report <span className="text-rose-400">*</span>
            </label>
            <select
              id="reassign-select"
              value={selectedOwnerId}
              onChange={e => setSelectedOwnerId(e.target.value)}
              className="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/30 transition-colors"
            >
              <option value="">— Select direct report —</option>
              {directReports.map(r => (
                <option key={r.id} value={r.id} disabled={r.id === task.owner?.id}>
                  {r.name} ({r.email}) {r.id === task.owner?.id ? '— Current' : ''}
                </option>
              ))}
            </select>
            <p className="text-[11px] text-slate-500 mt-1">
              Managers may only assign tasks to their verified direct reports.
            </p>
          </div>

          {error && (
            <div className="flex items-center gap-2 text-rose-400 text-xs bg-rose-500/10 border border-rose-500/20 rounded-lg px-3 py-2">
              <AlertCircle className="w-3.5 h-3.5 flex-shrink-0" />
              {error}
            </div>
          )}

          <div className="flex gap-3 pt-2">
            <button
              type="button"
              onClick={onClose}
              className="flex-1 py-2 rounded-lg border border-slate-700 text-slate-300 text-sm hover:bg-slate-800 transition-colors"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={saving || !selectedOwnerId}
              className="flex-1 py-2 rounded-lg bg-sky-600 hover:bg-sky-500 text-white text-sm font-medium transition-colors disabled:opacity-50 flex items-center justify-center gap-2"
            >
              {saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <UserCheck className="w-3.5 h-3.5" />}
              {saving ? 'Reassigning…' : 'Confirm Reassign'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function DeadlineModal({ task, onClose, onUpdateDeadline }) {
  const [dueDate, setDueDate] = useState(task.due_date || '');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);

  async function handleSubmit(e) {
    e.preventDefault();
    if (!dueDate) {
      setError('Please select a deadline.');
      return;
    }
    setSaving(true);
    setError(null);
    try {
      await onUpdateDeadline(task.id, dueDate);
      onClose();
    } catch (err) {
      setError(err?.apiError?.message ?? 'Failed to update deadline.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
      <div className="w-full max-w-md rounded-2xl bg-slate-900 border border-slate-700/80 shadow-2xl overflow-hidden">
        <div className="flex items-center justify-between px-6 py-4 border-b border-slate-800">
          <div className="flex items-center gap-2.5">
            <div className="w-8 h-8 rounded-lg bg-indigo-500/20 flex items-center justify-center">
              <Calendar className="w-4 h-4 text-indigo-400" />
            </div>
            <div>
              <h2 className="font-semibold text-white text-sm">Change Deadline</h2>
              <p className="text-xs text-slate-400 truncate max-w-xs">{task.title}</p>
            </div>
          </div>
          <button onClick={onClose} className="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors">
            <X className="w-4 h-4" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="p-6 space-y-4">
          <div>
            <label className="block text-xs font-medium text-slate-300 mb-1.5">
              New Due Date <span className="text-rose-400">*</span>
            </label>
            <input
              id="deadline-input"
              type="date"
              value={dueDate}
              onChange={e => setDueDate(e.target.value)}
              className="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30 transition-colors"
            />
          </div>

          {error && (
            <div className="flex items-center gap-2 text-rose-400 text-xs bg-rose-500/10 border border-rose-500/20 rounded-lg px-3 py-2">
              <AlertCircle className="w-3.5 h-3.5 flex-shrink-0" />
              {error}
            </div>
          )}

          <div className="flex gap-3 pt-2">
            <button
              type="button"
              onClick={onClose}
              className="flex-1 py-2 rounded-lg border border-slate-700 text-slate-300 text-sm hover:bg-slate-800 transition-colors"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={saving || !dueDate}
              className="flex-1 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium transition-colors disabled:opacity-50 flex items-center justify-center gap-2"
            >
              {saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Calendar className="w-3.5 h-3.5" />}
              {saving ? 'Updating…' : 'Save Deadline'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ─── Manager Comment Panel ──────────────────────────────────────────────────

function ManagerCommentPanel({ task, onComment }) {
  const [body, setBody] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);

  async function submit(e) {
    e.preventDefault();
    if (!body.trim()) return;
    setSaving(true);
    setError(null);
    try {
      await onComment(task.id, body.trim());
      setBody('');
    } catch (err) {
      setError(err?.apiError?.message ?? 'Failed to post comment.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="border-t border-slate-800 mt-3 pt-3 space-y-2">
      {task.comments?.length > 0 && (
        <div className="space-y-1.5 max-h-36 overflow-y-auto pr-1">
          {task.comments.map(c => (
            <div key={c.id} className="text-xs bg-slate-800/70 rounded-lg p-2.5 border border-slate-700/50">
              <div className="flex items-center justify-between text-slate-400 text-[11px] mb-1">
                <span className="text-sky-400 font-medium">{c.author_name}</span>
                <span>{new Date(c.created_at).toLocaleDateString()}</span>
              </div>
              <p className="text-slate-200">{c.body}</p>
            </div>
          ))}
        </div>
      )}
      <form onSubmit={submit} className="flex gap-2">
        <input
          value={body}
          onChange={e => setBody(e.target.value)}
          placeholder="Manager guidance or update…"
          className="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-sky-500 transition-colors"
        />
        <button
          type="submit"
          disabled={saving || !body.trim()}
          className="px-3 py-1.5 rounded-lg bg-sky-600 hover:bg-sky-500 text-white text-xs font-medium disabled:opacity-40 transition-colors flex items-center gap-1"
        >
          {saving ? <Loader2 className="w-3 h-3 animate-spin" /> : 'Post'}
        </button>
      </form>
      {error && <p className="text-xs text-rose-400">{error}</p>}
    </div>
  );
}

// ─── Team Task Card ─────────────────────────────────────────────────────────

function TeamTaskCard({ task, directReports, onAction }) {
  const [expanded, setExpanded] = useState(false);
  const [showComments, setShowComments] = useState(false);
  const [showReassign, setShowReassign] = useState(false);
  const [showDeadline, setShowDeadline] = useState(false);
  const [busyAction, setBusyAction] = useState(null);
  const [error, setError] = useState(null);

  const isBlocked = task.status === 'blocked';
  const isCompleted = task.status === 'completed';
  const isOverdue = task.bucket === 'overdue';

  async function handleAction(name, fn) {
    setBusyAction(name);
    setError(null);
    try {
      await fn();
    } catch (err) {
      setError(err?.apiError?.message ?? `Action failed: ${name}`);
    } finally {
      setBusyAction(null);
    }
  }

  return (
    <>
      <div className={`rounded-xl border transition-all duration-200 ${
        isCompleted
          ? 'border-slate-800/80 bg-slate-900/40 opacity-70'
          : isBlocked
            ? 'border-orange-500/40 bg-orange-950/15 shadow-sm'
            : isOverdue
              ? 'border-rose-500/30 bg-rose-950/10'
              : 'border-slate-800 bg-slate-900/80 hover:border-slate-700'
      }`}>
        <div className="p-4">
          <div className="flex items-start justify-between gap-3">
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-2 flex-wrap">
                <span className={`px-2 py-0.5 rounded-full text-[10px] font-semibold border ${STATUS_BADGES[task.status] ?? 'text-slate-400 border-slate-700'}`}>
                  {STATUS_LABELS[task.status] ?? task.status}
                </span>
                <span className={`px-2 py-0.5 rounded-full text-[10px] font-semibold border capitalize ${PRIORITY_BADGES[task.priority] ?? 'text-slate-400'}`}>
                  {task.priority}
                </span>
                {task.escalation_level > 0 && (
                  <span className="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/20 text-amber-300 border border-amber-500/40 flex items-center gap-1">
                    <Flame className="w-2.5 h-2.5" />
                    Escalation L{task.escalation_level}
                  </span>
                )}
              </div>

              <h3 className={`text-sm font-semibold mt-2 leading-snug ${isCompleted ? 'line-through text-slate-500' : 'text-white'}`}>
                {task.title}
              </h3>

              <div className="flex flex-wrap items-center gap-3 mt-2 text-xs text-slate-400">
                <div className="flex items-center gap-1.5">
                  <div className="w-5 h-5 rounded-full bg-sky-500/20 text-sky-300 flex items-center justify-center text-[10px] font-bold">
                    {task.owner?.name?.charAt(0) ?? '?'}
                  </div>
                  <span className="text-slate-300 font-medium">{task.owner?.name ?? 'Unassigned'}</span>
                </div>
                {task.due_date && (
                  <div className={`flex items-center gap-1 ${isOverdue && !isCompleted ? 'text-rose-400 font-medium' : 'text-slate-400'}`}>
                    <Clock className="w-3.5 h-3.5" />
                    <span>Due {new Date(task.due_date).toLocaleDateString()}</span>
                  </div>
                )}
              </div>

              {/* Blocker alert callout */}
              {isBlocked && task.blocker && (
                <div className="mt-3 p-3 rounded-lg bg-orange-500/10 border border-orange-500/30 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                  <div className="space-y-0.5">
                    <div className="flex items-center gap-1.5 text-xs font-semibold text-orange-300">
                      <Lock className="w-3.5 h-3.5" />
                      <span>{REASON_LABELS[task.blocker.reason_code] ?? task.blocker.reason_code}</span>
                    </div>
                    {task.blocker.description && (
                      <p className="text-xs text-orange-200/80 pl-5">{task.blocker.description}</p>
                    )}
                  </div>
                  <button
                    id={`task-${task.id}-resolve-blocker`}
                    onClick={() => handleAction('resolve', () => onAction('resolve-blocker', task.id))}
                    disabled={busyAction !== null}
                    className="self-start sm:self-auto flex items-center gap-1 px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-medium transition-colors shadow-sm disabled:opacity-50"
                  >
                    {busyAction === 'resolve' ? <Loader2 className="w-3 h-3 animate-spin" /> : <Unlock className="w-3 h-3" />}
                    Resolve Blocker
                  </button>
                </div>
              )}
            </div>

            <button
              onClick={() => setExpanded(v => !v)}
              className="p-1.5 rounded text-slate-500 hover:text-slate-300 transition-colors flex-shrink-0"
              aria-label="Toggle details"
            >
              {expanded ? <ChevronUp className="w-4 h-4" /> : <ChevronDown className="w-4 h-4" />}
            </button>
          </div>

          {/* Action Toolbar */}
          {!isCompleted && (
            <div className="flex flex-wrap gap-1.5 mt-3 pt-2 border-t border-slate-800/80">
              <button
                id={`task-${task.id}-reassign`}
                onClick={() => setShowReassign(true)}
                disabled={busyAction !== null}
                className="flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-300 text-xs font-medium transition-colors"
              >
                <UserCheck className="w-3 h-3 text-sky-400" />
                Reassign
              </button>

              <button
                id={`task-${task.id}-deadline`}
                onClick={() => setShowDeadline(true)}
                disabled={busyAction !== null}
                className="flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-300 text-xs font-medium transition-colors"
              >
                <Calendar className="w-3 h-3 text-indigo-400" />
                Change Deadline
              </button>

              <button
                id={`task-${task.id}-escalate`}
                onClick={() => handleAction('escalate', () => onAction('escalate', task.id, { escalation_level: (task.escalation_level || 0) + 1 }))}
                disabled={busyAction !== null}
                className="flex items-center gap-1 px-2.5 py-1 rounded-lg bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/30 text-amber-300 text-xs font-medium transition-colors"
              >
                {busyAction === 'escalate' ? <Loader2 className="w-3 h-3 animate-spin" /> : <Flame className="w-3 h-3" />}
                Escalate
              </button>

              <button
                id={`task-${task.id}-comment`}
                onClick={() => { setShowComments(v => !v); setExpanded(true); }}
                className="flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-300 text-xs font-medium transition-colors"
              >
                <MessageSquare className="w-3 h-3 text-emerald-400" />
                Comment {task.comments?.length > 0 && `(${task.comments.length})`}
              </button>
            </div>
          )}

          {error && (
            <p className="mt-2 text-xs text-rose-400 flex items-center gap-1">
              <AlertCircle className="w-3 h-3" /> {error}
            </p>
          )}
        </div>

        {/* Expanded Description & Comments */}
        {expanded && (
          <div className="px-4 pb-4 border-t border-slate-800/60 pt-3">
            {task.description && (
              <p className="text-xs text-slate-400 leading-relaxed mb-2">{task.description}</p>
            )}
            {(showComments || task.comments?.length > 0) && (
              <ManagerCommentPanel
                task={task}
                onComment={(id, body) => onAction('comment', id, body)}
              />
            )}
          </div>
        )}
      </div>

      {/* Reassign Modal */}
      {showReassign && (
        <ReassignModal
          task={task}
          directReports={directReports}
          onClose={() => setShowReassign(false)}
          onReassign={(id, newOwnerId) => onAction('reassign', id, newOwnerId)}
        />
      )}

      {/* Deadline Modal */}
      {showDeadline && (
        <DeadlineModal
          task={task}
          onClose={() => setShowDeadline(false)}
          onUpdateDeadline={(id, newDate) => onAction('deadline', id, newDate)}
        />
      )}
    </>
  );
}

// ─── Main Manager Dashboard ─────────────────────────────────────────────────

export const ManagerDashboard = () => {
  const { user } = useAuth();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [selectedReportId, setSelectedReportId] = useState(null);
  const [statusFilter, setStatusFilter] = useState('all');
  const [searchQuery, setSearchQuery] = useState('');

  const loadData = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await managerApi.dashboard();
      setData(res.data);
    } catch (err) {
      setError(err?.apiError?.message ?? 'Failed to load team dashboard.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadData();
  }, [loadData]);

  // Central mutation handler
  async function handleTaskAction(type, taskId, payload) {
    switch (type) {
      case 'reassign':
        await managerApi.reassign(taskId, payload);
        break;
      case 'deadline':
        await managerApi.updateDeadline(taskId, payload);
        break;
      case 'comment':
        await managerApi.comment(taskId, payload);
        break;
      case 'resolve-blocker':
        await managerApi.resolveBlocker(taskId);
        break;
      case 'escalate':
        await managerApi.escalate(taskId, payload?.escalation_level);
        break;
      default:
        throw new Error(`Unknown action: ${type}`);
    }
    await loadData();
  }

  // Filter tasks locally by active direct report tab, status, and search query
  const directReports = data?.direct_reports ?? [];
  const allTasks = data?.tasks ?? [];

  const filteredTasks = allTasks.filter(task => {
    if (selectedReportId !== null && task.owner?.id !== selectedReportId) {
      return false;
    }
    if (statusFilter === 'overdue' && (task.bucket !== 'overdue' || task.status === 'completed')) {
      return false;
    }
    if (statusFilter === 'blocked' && task.status !== 'blocked') {
      return false;
    }
    if (statusFilter === 'upcoming' && (task.status === 'completed' || task.bucket === 'overdue')) {
      return false;
    }
    if (statusFilter === 'completed' && task.status !== 'completed') {
      return false;
    }
    if (searchQuery.trim()) {
      const q = searchQuery.toLowerCase();
      const matchTitle = task.title?.toLowerCase().includes(q);
      const matchDesc = task.description?.toLowerCase().includes(q);
      const matchOwner = task.owner?.name?.toLowerCase().includes(q);
      if (!matchTitle && !matchDesc && !matchOwner) return false;
    }
    return true;
  });

  const metrics = data?.metrics ?? {
    total_tasks: 0,
    completed_tasks: 0,
    overdue_tasks: 0,
    blocked_tasks: 0,
    upcoming_tasks: 0,
    completion_rate: 100,
  };

  return (
    <div className="space-y-6">
      {/* Header Banner */}
      <div className="p-6 rounded-2xl glass-panel border border-slate-800/80 bg-gradient-to-r from-slate-900 via-sky-950/25 to-slate-900 shadow-xl">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div>
            <div className="flex items-center gap-2">
              <span className="text-xs font-semibold uppercase tracking-wider text-sky-400">
                Management Oversight
              </span>
              <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-sky-500/20 text-sky-300 border border-sky-500/30">
                Direct Reports Only
              </span>
            </div>
            <h1 className="text-2xl font-bold text-white mt-1">Team Dashboard</h1>
            <p className="text-sm text-slate-400 mt-1">
              Direct reports under {user?.name}{directReports.length > 0 ? `: ${directReports.map(r => r.name).join(', ')}.` : '.'}
            </p>
          </div>
          <div className="flex items-center gap-3">
            <button
              id="manager-dashboard-refresh"
              onClick={loadData}
              disabled={loading}
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-700 text-slate-300 hover:text-white hover:border-slate-600 text-xs transition-colors disabled:opacity-40"
            >
              <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
              Refresh
            </button>
          </div>
        </div>
      </div>

      {/* Error alert */}
      {error && (
        <div className="flex items-center gap-3 p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-300 text-sm">
          <AlertCircle className="w-5 h-5 flex-shrink-0" />
          <span>{error}</span>
          <button onClick={loadData} className="ml-auto text-xs underline hover:opacity-80">Retry</button>
        </div>
      )}

      {/* Metrics Row */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* Team Tasks */}
        <div className="p-4 rounded-xl glass-card border border-slate-800 bg-slate-900/60 shadow-sm">
          <div className="flex items-center justify-between text-slate-400 text-xs font-medium">
            <span>Team Tasks</span>
            <Users className="w-4 h-4 text-sky-400" />
          </div>
          <div className="text-2xl font-bold text-white mt-1.5">{metrics.total_tasks}</div>
          <span className="text-[11px] text-slate-500">Across direct reports</span>
        </div>

        {/* Overdue */}
        <div className={`p-4 rounded-xl glass-card border transition-all ${
          metrics.overdue_tasks > 0 ? 'border-rose-500/30 bg-rose-950/10' : 'border-slate-800 bg-slate-900/60'
        }`}>
          <div className="flex items-center justify-between text-xs font-medium">
            <span className={metrics.overdue_tasks > 0 ? 'text-rose-400' : 'text-slate-400'}>Overdue</span>
            <AlertTriangle className={`w-4 h-4 ${metrics.overdue_tasks > 0 ? 'text-rose-400 animate-pulse' : 'text-slate-500'}`} />
          </div>
          <div className={`text-2xl font-bold mt-1.5 ${metrics.overdue_tasks > 0 ? 'text-rose-400' : 'text-white'}`}>
            {metrics.overdue_tasks}
          </div>
          <span className="text-[11px] text-slate-500">Passed deadline date</span>
        </div>

        {/* Blocked */}
        <div className={`p-4 rounded-xl glass-card border transition-all ${
          metrics.blocked_tasks > 0 ? 'border-amber-500/30 bg-amber-950/10' : 'border-slate-800 bg-slate-900/60'
        }`}>
          <div className="flex items-center justify-between text-xs font-medium">
            <span className={metrics.blocked_tasks > 0 ? 'text-amber-400' : 'text-slate-400'}>Active Blockers</span>
            <ShieldAlert className={`w-4 h-4 ${metrics.blocked_tasks > 0 ? 'text-amber-400' : 'text-slate-500'}`} />
          </div>
          <div className={`text-2xl font-bold mt-1.5 ${metrics.blocked_tasks > 0 ? 'text-amber-400' : 'text-white'}`}>
            {metrics.blocked_tasks}
          </div>
          <span className="text-[11px] text-slate-500">Awaiting manager resolution</span>
        </div>

        {/* Completion Rate */}
        <div className="p-4 rounded-xl glass-card border border-slate-800 bg-slate-900/60 shadow-sm">
          <div className="flex items-center justify-between text-slate-400 text-xs font-medium">
            <span>Completion Rate</span>
            <TrendingUp className="w-4 h-4 text-emerald-400" />
          </div>
          <div className="text-2xl font-bold text-emerald-400 mt-1.5">{metrics.completion_rate}%</div>
          <div className="w-full bg-slate-800 rounded-full h-1.5 mt-2 overflow-hidden">
            <div
              className="bg-emerald-500 h-full rounded-full transition-all duration-500"
              style={{ width: `${Math.min(metrics.completion_rate, 100)}%` }}
            />
          </div>
        </div>
      </div>

      {/* Direct Report Roster Tabs */}
      <div className="space-y-3">
        <h2 className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Direct Reports</h2>
        <div className="flex flex-wrap gap-2">
          <button
            id="report-filter-all"
            onClick={() => setSelectedReportId(null)}
            className={`px-3 py-2 rounded-xl text-xs font-medium transition-all flex items-center gap-2 ${
              selectedReportId === null
                ? 'bg-sky-600 text-white shadow-md shadow-sky-600/20'
                : 'bg-slate-800/80 text-slate-300 hover:bg-slate-800 border border-slate-700/60'
            }`}
          >
            <Users className="w-3.5 h-3.5" />
            <span>All Direct Reports</span>
            <span className="px-1.5 py-0.5 rounded-full text-[10px] bg-white/20 text-white font-bold">
              {directReports.length}
            </span>
          </button>

          {directReports.map(report => (
            <button
              key={report.id}
              id={`report-filter-${report.id}`}
              onClick={() => setSelectedReportId(report.id)}
              className={`px-3 py-2 rounded-xl text-xs font-medium transition-all flex items-center gap-2 ${
                selectedReportId === report.id
                  ? 'bg-sky-600 text-white shadow-md shadow-sky-600/20'
                  : 'bg-slate-800/80 text-slate-300 hover:bg-slate-800 border border-slate-700/60'
              }`}
            >
              <div className="w-4 h-4 rounded-full bg-slate-700 text-sky-300 text-[9px] font-bold flex items-center justify-center">
                {report.name.charAt(0)}
              </div>
              <span>{report.name}</span>
              {report.blocked_tasks > 0 ? (
                <span className="px-1.5 py-0.5 rounded-full text-[10px] bg-orange-500/20 text-orange-300 font-bold border border-orange-500/30">
                  {report.blocked_tasks} blk
                </span>
              ) : (
                <span className="px-1.5 py-0.5 rounded-full text-[10px] bg-slate-700 text-slate-400 font-medium">
                  {report.open_tasks}
                </span>
              )}
            </button>
          ))}
        </div>
      </div>

      {/* Filter and Search Bar */}
      <div className="flex flex-col sm:flex-row items-center justify-between gap-3 pt-2">
        {/* Status Bucket Filters */}
        <div className="flex flex-wrap gap-1.5 w-full sm:w-auto">
          {[
            { id: 'all',       label: 'All Tasks' },
            { id: 'overdue',   label: `Overdue (${metrics.overdue_tasks})` },
            { id: 'blocked',   label: `Blocked (${metrics.blocked_tasks})` },
            { id: 'upcoming',  label: `Upcoming (${metrics.upcoming_tasks})` },
            { id: 'completed', label: `Completed (${metrics.completed_tasks})` },
          ].map(f => (
            <button
              key={f.id}
              id={`status-filter-${f.id}`}
              onClick={() => setStatusFilter(f.id)}
              className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${
                statusFilter === f.id
                  ? 'bg-slate-700 text-white'
                  : 'text-slate-400 hover:text-white hover:bg-slate-800'
              }`}
            >
              {f.label}
            </button>
          ))}
        </div>

        {/* Search */}
        <div className="relative w-full sm:w-64">
          <Search className="w-3.5 h-3.5 text-slate-500 absolute left-3 top-2.5" />
          <input
            type="text"
            placeholder="Search team tasks…"
            value={searchQuery}
            onChange={e => setSearchQuery(e.target.value)}
            className="w-full bg-slate-800 border border-slate-700 rounded-lg pl-8 pr-3 py-1.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-sky-500 transition-colors"
          />
        </div>
      </div>

      {/* Task List */}
      <div className="space-y-3">
        {loading && !data && (
          <div className="space-y-3">
            {[...Array(3)].map((_, i) => (
              <div key={i} className="h-28 rounded-xl bg-slate-900/60 border border-slate-800 animate-pulse" />
            ))}
          </div>
        )}

        {!loading && filteredTasks.length === 0 && (
          <div className="text-center py-12 rounded-2xl border border-slate-800/80 bg-slate-900/40">
            <CheckCircle2 className="w-8 h-8 text-emerald-400/60 mx-auto mb-2" />
            <h3 className="text-sm font-semibold text-white">No tasks matching criteria</h3>
            <p className="text-xs text-slate-400 mt-1 max-w-sm mx-auto">
              {selectedReportId !== null
                ? 'This direct report currently has no tasks in the chosen filter.'
                : 'All clear across your direct reports for this category.'}
            </p>
          </div>
        )}

        {filteredTasks.map(task => (
          <TeamTaskCard
            key={task.id}
            task={task}
            directReports={directReports}
            onAction={handleTaskAction}
          />
        ))}
      </div>
    </div>
  );
};
