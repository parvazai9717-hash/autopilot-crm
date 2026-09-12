import React, { useState, useEffect, useRef, useCallback } from 'react';
import { useAuth } from '../context/AuthContext';
import { myTasksApi } from '../services/api';
import {
  AlertTriangle, Clock, CheckSquare, Play, CheckCircle2,
  ShieldAlert, MessageSquare, Paperclip, ChevronDown, ChevronUp,
  X, Loader2, RefreshCw, AlertCircle, Lock, Unlock,
} from 'lucide-react';

// ─── Constants ──────────────────────────────────────────────────────────────

const REASON_CODES = [
  { value: 'waiting_for_person',     label: 'Waiting for a person' },
  { value: 'waiting_for_info',       label: 'Waiting for information' },
  { value: 'waiting_for_approval',   label: 'Waiting for approval' },
  { value: 'technical_issue',        label: 'Technical issue / tooling' },
  { value: 'unclear_requirement',    label: 'Unclear requirement' },
  { value: 'other',                  label: 'Other' },
];

const PRIORITY_COLORS = {
  high:   'text-rose-400 bg-rose-500/10 border-rose-500/30',
  medium: 'text-amber-400 bg-amber-500/10 border-amber-500/30',
  low:    'text-sky-400  bg-sky-500/10  border-sky-500/30',
};

const STATUS_LABELS = {
  assigned:    'Assigned',
  approved:    'Approved',
  in_progress: 'In Progress',
  blocked:     'Blocked',
  overdue:     'Overdue',
  escalated:   'Escalated',
  completed:   'Completed',
};

// ─── Blocker Modal ───────────────────────────────────────────────────────────

function BlockerModal({ task, openTasks, onClose, onSubmit }) {
  const [reasonCode, setReasonCode]   = useState('');
  const [description, setDescription] = useState('');
  const [dependsOn, setDependsOn]     = useState('');
  const [saving, setSaving]           = useState(false);
  const [error, setError]             = useState(null);

  const availableTasks = openTasks.filter(t => t.id !== task.id);

  async function handleSubmit(e) {
    e.preventDefault();
    if (!reasonCode) { setError('Please choose a reason.'); return; }
    setSaving(true);
    setError(null);
    try {
      await onSubmit(task.id, {
        reason_code:         reasonCode,
        description:         description || undefined,
        depends_on_task_id:  dependsOn ? parseInt(dependsOn, 10) : undefined,
      });
      onClose();
    } catch (err) {
      setError(err?.apiError?.message ?? 'Failed to block task. Please try again.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
      <div className="w-full max-w-lg rounded-2xl bg-slate-900 border border-slate-700/80 shadow-2xl overflow-hidden">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-slate-800">
          <div className="flex items-center gap-3">
            <div className="w-8 h-8 rounded-lg bg-orange-500/20 flex items-center justify-center">
              <Lock className="w-4 h-4 text-orange-400" />
            </div>
            <div>
              <h2 className="font-semibold text-white text-sm">I'm Blocked</h2>
              <p className="text-xs text-slate-400 truncate max-w-xs">{task.title}</p>
            </div>
          </div>
          <button onClick={onClose} className="p-1.5 rounded-lg hover:bg-slate-800 text-slate-400 hover:text-white transition-colors">
            <X className="w-4 h-4" />
          </button>
        </div>

        {/* Form */}
        <form onSubmit={handleSubmit} className="p-6 space-y-4">
          {/* Reason – required */}
          <div>
            <label className="block text-xs font-medium text-slate-300 mb-1.5">
              Reason <span className="text-rose-400">*</span>
            </label>
            <select
              id="blocker-reason"
              value={reasonCode}
              onChange={e => setReasonCode(e.target.value)}
              className="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30 transition-colors"
            >
              <option value="">— Select a reason —</option>
              {REASON_CODES.map(r => (
                <option key={r.value} value={r.value}>{r.label}</option>
              ))}
            </select>
          </div>

          {/* Description – optional */}
          <div>
            <label className="block text-xs font-medium text-slate-300 mb-1.5">
              Description <span className="text-slate-500 font-normal">(optional)</span>
            </label>
            <textarea
              id="blocker-description"
              value={description}
              onChange={e => setDescription(e.target.value)}
              rows={3}
              placeholder="Briefly describe what you're waiting on…"
              className="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30 resize-none transition-colors"
            />
          </div>

          {/* Dependency – optional */}
          <div>
            <label className="block text-xs font-medium text-slate-300 mb-1">
              Are you waiting on another task?{' '}
              <span className="text-slate-500 font-normal">(optional — builds the dependency graph)</span>
            </label>
            <select
              id="blocker-depends-on"
              value={dependsOn}
              onChange={e => setDependsOn(e.target.value)}
              className="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30 transition-colors"
            >
              <option value="">— None —</option>
              {availableTasks.map(t => (
                <option key={t.id} value={t.id}>
                  #{t.id} — {t.title}{t.owner_name ? ` (${t.owner_name})` : ''}
                </option>
              ))}
            </select>
          </div>

          {error && (
            <div className="flex items-center gap-2 text-rose-400 text-xs bg-rose-500/10 border border-rose-500/20 rounded-lg px-3 py-2">
              <AlertCircle className="w-3.5 h-3.5 flex-shrink-0" />
              {error}
            </div>
          )}

          <div className="flex gap-3 pt-1">
            <button
              type="button"
              onClick={onClose}
              className="flex-1 py-2 rounded-lg border border-slate-700 text-slate-300 text-sm hover:bg-slate-800 transition-colors"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={saving}
              className="flex-1 py-2 rounded-lg bg-orange-600 hover:bg-orange-500 text-white text-sm font-medium transition-colors disabled:opacity-50 flex items-center justify-center gap-2"
            >
              {saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Lock className="w-3.5 h-3.5" />}
              {saving ? 'Submitting…' : 'Mark as Blocked'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ─── Comment Panel ───────────────────────────────────────────────────────────

function CommentPanel({ task, onComment }) {
  const [body, setBody]       = useState('');
  const [saving, setSaving]   = useState(false);
  const [error, setError]     = useState(null);

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
        <div className="space-y-1.5 max-h-32 overflow-y-auto pr-1">
          {task.comments.map(c => (
            <div key={c.id} className="text-xs bg-slate-800/60 rounded-lg px-3 py-2">
              <span className="text-indigo-400 font-medium">{c.author_name}</span>
              <span className="text-slate-500 ml-1">·</span>
              <span className="text-slate-500 ml-1">{new Date(c.created_at).toLocaleDateString()}</span>
              <p className="text-slate-300 mt-0.5">{c.body}</p>
            </div>
          ))}
        </div>
      )}
      <form onSubmit={submit} className="flex gap-2">
        <input
          value={body}
          onChange={e => setBody(e.target.value)}
          placeholder="Add a comment…"
          className="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500 transition-colors"
        />
        <button
          type="submit"
          disabled={saving || !body.trim()}
          className="px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-medium disabled:opacity-40 transition-colors flex items-center gap-1"
        >
          {saving ? <Loader2 className="w-3 h-3 animate-spin" /> : 'Post'}
        </button>
      </form>
      {error && <p className="text-xs text-rose-400">{error}</p>}
    </div>
  );
}

// ─── Task Card ───────────────────────────────────────────────────────────────

function TaskCard({ task, openTasks, onAction }) {
  const [expanded, setExpanded]     = useState(false);
  const [showComment, setShowComment] = useState(false);
  const [showBlocker, setShowBlocker] = useState(false);
  const [actionBusy, setActionBusy] = useState(null);
  const [error, setError]           = useState(null);
  const fileRef                     = useRef();

  const isBlocked   = task.status === 'blocked';
  const isCompleted = task.status === 'completed';
  const canStart    = ['assigned', 'approved'].includes(task.status);
  const canComplete = ['assigned', 'in_progress', 'overdue', 'escalated'].includes(task.status);
  const canBlock    = !isBlocked && !isCompleted;

  async function doAction(name, fn) {
    setActionBusy(name);
    setError(null);
    try {
      await fn();
    } catch (err) {
      setError(err?.apiError?.message ?? `Failed: ${name}`);
    } finally {
      setActionBusy(null);
    }
  }

  function priorityBadge() {
    const cls = PRIORITY_COLORS[task.priority] ?? 'text-slate-400 bg-slate-700 border-slate-600';
    return (
      <span className={`px-2 py-0.5 rounded-full text-[10px] font-semibold border capitalize ${cls}`}>
        {task.priority}
      </span>
    );
  }

  function statusBadge() {
    const labels = { ...STATUS_LABELS };
    const label  = labels[task.status] ?? task.status;
    const cls = {
      assigned:    'text-indigo-300 bg-indigo-500/10 border-indigo-500/20',
      approved:    'text-teal-300 bg-teal-500/10 border-teal-500/20',
      in_progress: 'text-emerald-300 bg-emerald-500/10 border-emerald-500/20',
      blocked:     'text-orange-300 bg-orange-500/10 border-orange-500/20',
      overdue:     'text-rose-300 bg-rose-500/10 border-rose-500/20',
      escalated:   'text-yellow-300 bg-yellow-500/10 border-yellow-500/20',
      completed:   'text-slate-400 bg-slate-700/40 border-slate-600/30',
    }[task.status] ?? 'text-slate-400 bg-slate-700 border-slate-600';

    return (
      <span className={`px-2 py-0.5 rounded-full text-[10px] font-semibold border ${cls}`}>
        {label}
      </span>
    );
  }

  return (
    <>
      <div className={`rounded-xl border transition-all duration-200 ${
        isCompleted
          ? 'border-slate-800 bg-slate-900/40 opacity-60'
          : isBlocked
            ? 'border-orange-500/30 bg-orange-950/10'
            : 'border-slate-800 bg-slate-900/70 hover:border-slate-700'
      }`}>
        {/* Card header */}
        <div className="p-4">
          <div className="flex items-start justify-between gap-2">
            <div className="flex-1 min-w-0">
              <p className={`text-sm font-medium leading-snug ${isCompleted ? 'line-through text-slate-500' : 'text-white'}`}>
                {task.title}
              </p>
              <div className="flex flex-wrap items-center gap-1.5 mt-1.5">
                {statusBadge()}
                {priorityBadge()}
                {task.due_date && (
                  <span className="text-[10px] text-slate-500">
                    Due {new Date(task.due_date).toLocaleDateString()}
                  </span>
                )}
              </div>
              {isBlocked && task.blocker && (
                <p className="mt-1.5 text-[11px] text-orange-400/80 flex items-center gap-1">
                  <Lock className="w-3 h-3" />
                  {REASON_CODES.find(r => r.value === task.blocker.reason_code)?.label ?? task.blocker.reason_code}
                  {task.blocker.description ? ` — ${task.blocker.description}` : ''}
                </p>
              )}
            </div>
            <button
              onClick={() => setExpanded(v => !v)}
              className="p-1 rounded text-slate-500 hover:text-slate-300 transition-colors flex-shrink-0"
              aria-label="Toggle task details"
            >
              {expanded ? <ChevronUp className="w-4 h-4" /> : <ChevronDown className="w-4 h-4" />}
            </button>
          </div>

          {/* Action buttons */}
          {!isCompleted && (
            <div className="flex flex-wrap gap-1.5 mt-3">
              {canStart && (
                <button
                  id={`task-${task.id}-start`}
                  onClick={() => doAction('start', () => onAction('start', task.id))}
                  disabled={actionBusy !== null}
                  className="flex items-center gap-1 px-2.5 py-1 rounded-lg bg-emerald-600/20 hover:bg-emerald-600/30 border border-emerald-500/30 text-emerald-300 text-xs font-medium transition-colors disabled:opacity-40"
                >
                  {actionBusy === 'start' ? <Loader2 className="w-3 h-3 animate-spin" /> : <Play className="w-3 h-3" />}
                  Start
                </button>
              )}
              {canComplete && (
                <button
                  id={`task-${task.id}-complete`}
                  onClick={() => doAction('complete', () => onAction('complete', task.id))}
                  disabled={actionBusy !== null}
                  className="flex items-center gap-1 px-2.5 py-1 rounded-lg bg-indigo-600/20 hover:bg-indigo-600/30 border border-indigo-500/30 text-indigo-300 text-xs font-medium transition-colors disabled:opacity-40"
                >
                  {actionBusy === 'complete' ? <Loader2 className="w-3 h-3 animate-spin" /> : <CheckCircle2 className="w-3 h-3" />}
                  Complete
                </button>
              )}
              {canBlock && (
                <button
                  id={`task-${task.id}-block`}
                  onClick={() => setShowBlocker(true)}
                  disabled={actionBusy !== null}
                  className="flex items-center gap-1 px-2.5 py-1 rounded-lg bg-orange-600/20 hover:bg-orange-600/30 border border-orange-500/30 text-orange-300 text-xs font-medium transition-colors disabled:opacity-40"
                >
                  <ShieldAlert className="w-3 h-3" />
                  I'm Blocked
                </button>
              )}
              {isBlocked && (
                <button
                  id={`task-${task.id}-unblock`}
                  onClick={() => doAction('unblock', () => onAction('unblock', task.id))}
                  disabled={actionBusy !== null}
                  className="flex items-center gap-1 px-2.5 py-1 rounded-lg bg-emerald-600/20 hover:bg-emerald-600/30 border border-emerald-500/30 text-emerald-300 text-xs font-medium transition-colors disabled:opacity-40"
                >
                  {actionBusy === 'unblock' ? <Loader2 className="w-3 h-3 animate-spin" /> : <Unlock className="w-3 h-3" />}
                  Unblock
                </button>
              )}
              <button
                id={`task-${task.id}-comment`}
                onClick={() => { setShowComment(v => !v); setExpanded(true); }}
                disabled={actionBusy !== null}
                className="flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-700/50 hover:bg-slate-700 border border-slate-700 text-slate-300 text-xs font-medium transition-colors disabled:opacity-40"
              >
                <MessageSquare className="w-3 h-3" />
                Comment {task.comments?.length > 0 && `(${task.comments.length})`}
              </button>
              <label
                id={`task-${task.id}-attach`}
                className="flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-700/50 hover:bg-slate-700 border border-slate-700 text-slate-300 text-xs font-medium transition-colors cursor-pointer"
              >
                {actionBusy === 'attach' ? <Loader2 className="w-3 h-3 animate-spin" /> : <Paperclip className="w-3 h-3" />}
                Attach
                <input
                  ref={fileRef}
                  type="file"
                  className="hidden"
                  onChange={async (e) => {
                    const file = e.target.files?.[0];
                    if (!file) return;
                    await doAction('attach', () => onAction('attach', task.id, file));
                    if (fileRef.current) fileRef.current.value = '';
                  }}
                />
              </label>
            </div>
          )}

          {error && (
            <p className="mt-2 text-xs text-rose-400 flex items-center gap-1">
              <AlertCircle className="w-3 h-3" /> {error}
            </p>
          )}
        </div>

        {/* Expanded details + comments */}
        {expanded && (
          <div className="px-4 pb-4 border-t border-slate-800/60 pt-3">
            {task.description && (
              <p className="text-xs text-slate-400 leading-relaxed mb-2">{task.description}</p>
            )}
            {(showComment || task.comments?.length > 0) && (
              <CommentPanel
                task={task}
                onComment={(id, body) => onAction('comment', id, body)}
              />
            )}
          </div>
        )}
      </div>

      {/* Blocker modal */}
      {showBlocker && (
        <BlockerModal
          task={task}
          openTasks={openTasks}
          onClose={() => setShowBlocker(false)}
          onSubmit={(id, payload) => onAction('block', id, payload)}
        />
      )}
    </>
  );
}

// ─── Section Column ──────────────────────────────────────────────────────────

function TaskSection({ label, icon: Icon, colorClass, borderClass, badgeClass, tasks, openTasks, onAction }) {
  return (
    <div className={`flex flex-col rounded-2xl border ${borderClass} bg-slate-900/50`}>
      {/* Section header */}
      <div className="flex items-center justify-between px-5 py-3 border-b border-slate-800">
        <div className="flex items-center gap-2">
          <div className={`w-7 h-7 rounded-lg flex items-center justify-center ${colorClass}`}>
            <Icon className="w-4 h-4" />
          </div>
          <h2 className="font-semibold text-sm text-slate-200">{label}</h2>
        </div>
        <span className={`px-2 py-0.5 rounded-full text-xs font-bold border ${badgeClass}`}>
          {tasks.length}
        </span>
      </div>

      {/* Task list */}
      <div className="flex-1 p-4 space-y-3 min-h-[120px]">
        {tasks.length === 0 ? (
          <div className="flex flex-col items-center justify-center h-full py-8 text-slate-600 text-xs text-center">
            <CheckSquare className="w-6 h-6 mb-2 opacity-40" />
            {label === 'Overdue' ? 'No overdue tasks — great work!' :
             label === 'Due Today' ? 'Nothing due today.' :
             'No upcoming tasks.'}
          </div>
        ) : (
          tasks.map(task => (
            <TaskCard
              key={task.id}
              task={task}
              openTasks={openTasks}
              onAction={onAction}
            />
          ))
        )}
      </div>
    </div>
  );
}

// ─── Main Page ───────────────────────────────────────────────────────────────

export const EmployeeDashboard = () => {
  const { user } = useAuth();
  const [data, setData]       = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await myTasksApi.index();
      setData(res.data);
    } catch (err) {
      setError(err?.apiError?.message ?? 'Failed to load tasks. Please refresh.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  // Central action dispatcher — refreshes after every mutation
  async function onAction(type, taskId, payload) {
    switch (type) {
      case 'start':    await myTasksApi.start(taskId);          break;
      case 'complete': await myTasksApi.complete(taskId);       break;
      case 'block':    await myTasksApi.block(taskId, payload); break;
      case 'unblock':  await myTasksApi.unblock(taskId);        break;
      case 'comment':  await myTasksApi.comment(taskId, payload); break;
      case 'attach':   await myTasksApi.attach(taskId, payload); break;
      default: throw new Error(`Unknown action: ${type}`);
    }
    await load();
  }

  const totalTasks = data
    ? (data.overdue?.length ?? 0) + (data.due_today?.length ?? 0) + (data.upcoming?.length ?? 0)
    : 0;

  return (
    <div className="space-y-6">
      {/* Hero Header */}
      <div className="p-6 rounded-2xl bg-gradient-to-r from-slate-900 via-indigo-950/20 to-slate-900 border border-slate-800/80">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div>
            <span className="text-xs font-semibold uppercase tracking-wider text-indigo-400">
              Employee Workspace
            </span>
            <h1 className="text-2xl font-bold text-white mt-1">My Tasks</h1>
            <p className="text-sm text-slate-400 mt-1">
              Welcome back, {user?.name}. Commitments captured and approved for you.
            </p>
          </div>
          <div className="flex items-center gap-3">
            {!loading && (
              <span className="text-xs text-slate-500">
                {totalTasks} active task{totalTasks !== 1 ? 's' : ''}
              </span>
            )}
            <button
              id="my-tasks-refresh"
              onClick={load}
              disabled={loading}
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-700 text-slate-400 hover:text-white hover:border-slate-600 text-xs transition-colors disabled:opacity-40"
            >
              <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
              Refresh
            </button>
          </div>
        </div>
      </div>

      {/* Error state */}
      {error && (
        <div className="flex items-center gap-3 p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-300 text-sm">
          <AlertCircle className="w-5 h-5 flex-shrink-0" />
          {error}
          <button onClick={load} className="ml-auto text-xs underline opacity-70 hover:opacity-100">
            Retry
          </button>
        </div>
      )}

      {/* Loading skeleton */}
      {loading && !data && (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          {[...Array(3)].map((_, i) => (
            <div key={i} className="rounded-2xl border border-slate-800 bg-slate-900/50 p-5 animate-pulse space-y-3">
              <div className="h-4 w-24 bg-slate-800 rounded" />
              {[...Array(2)].map((_, j) => (
                <div key={j} className="h-20 bg-slate-800 rounded-xl" />
              ))}
            </div>
          ))}
        </div>
      )}

      {/* Task columns */}
      {data && (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <TaskSection
            label="Overdue"
            icon={AlertTriangle}
            colorClass="bg-rose-500/20 text-rose-300"
            borderClass="border-rose-500/20"
            badgeClass="bg-rose-500/20 text-rose-300 border-rose-500/30"
            tasks={data.overdue}
            openTasks={data.open_tasks}
            onAction={onAction}
          />
          <TaskSection
            label="Due Today"
            icon={Clock}
            colorClass="bg-amber-500/20 text-amber-300"
            borderClass="border-amber-500/20"
            badgeClass="bg-amber-500/20 text-amber-300 border-amber-500/30"
            tasks={data.due_today}
            openTasks={data.open_tasks}
            onAction={onAction}
          />
          <TaskSection
            label="Upcoming"
            icon={CheckSquare}
            colorClass="bg-emerald-500/20 text-emerald-300"
            borderClass="border-emerald-500/20"
            badgeClass="bg-emerald-500/20 text-emerald-300 border-emerald-500/30"
            tasks={data.upcoming}
            openTasks={data.open_tasks}
            onAction={onAction}
          />
        </div>
      )}
    </div>
  );
};
