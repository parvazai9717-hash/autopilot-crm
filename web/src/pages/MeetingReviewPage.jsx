import React, { useState, useEffect, useMemo } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { meetingApi } from '../services/meetingApi';
import {
  FileCheck2,
  AlertTriangle,
  Clock,
  User,
  Users,
  Calendar,
  Flag,
  Check,
  X,
  Save,
  CheckCheck,
  ChevronLeft,
  Sparkles,
  Search,
  Quote,
  HelpCircle,
  ShieldAlert,
  Loader2,
  ExternalLink,
  MessageSquare,
  AlertCircle,
  FileText,
  BadgeCheck,
  Ban,
} from 'lucide-react';

export const MeetingReviewPage = () => {
  const { id } = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();

  const [loading, setLoading] = useState(true);
  const [meeting, setMeeting] = useState(null);
  const [tasks, setTasks] = useState([]);
  const [orgUsers, setOrgUsers] = useState([]);
  const [stats, setStats] = useState({ total: 0, pending: 0, approved: 0, rejected: 0, ambiguous: 0 });
  const [activeFilter, setActiveFilter] = useState('all'); // all, pending, approved, rejected, ambiguous
  const [transcriptSearch, setTranscriptSearch] = useState('');
  const [highlightedSourceText, setHighlightedSourceText] = useState(null);
  const [savingTaskId, setSavingTaskId] = useState(null);
  const [approvingTaskId, setApprovingTaskId] = useState(null);
  const [rejectingTaskId, setRejectingTaskId] = useState(null);
  const [isApprovingAll, setIsApprovingAll] = useState(false);
  const [notification, setNotification] = useState(null);
  const [error, setError] = useState(null);

  // Per-task local edit buffer to support instant inline editing
  const [taskEdits, setTaskEdits] = useState({});

  const showNotification = (message, type = 'success') => {
    setNotification({ message, type });
    setTimeout(() => setNotification(null), 5000);
  };

  const fetchMeetingData = async () => {
    try {
      setLoading(true);
      setError(null);
      const data = await meetingApi.getMeetingReview(id);
      setMeeting(data.meeting);
      setTasks(data.tasks);
      setOrgUsers(data.users || []);
      setStats(data.stats || {});

      // Initialize edit buffer
      const edits = {};
      data.tasks.forEach((t) => {
        edits[t.id] = {
          title: t.title || '',
          description: t.description || '',
          owner_id: t.owner_id ? String(t.owner_id) : '',
          due_date: t.due_date || '',
          priority: t.priority || 'medium',
          conditional: Boolean(t.conditional),
          conditional_ack: Boolean(t.conditional_ack),
          no_deadline_ack: Boolean(t.no_deadline_ack),
          isDirty: false,
        };
      });
      setTaskEdits(edits);
    } catch (err) {
      console.error('Failed to load meeting review:', err);
      setError(err.apiError?.message || 'Failed to load meeting review details.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchMeetingData();
  }, [id]);

  // Handle local form changes per task card
  const handleFieldChange = (taskId, field, value) => {
    setTaskEdits((prev) => {
      const current = prev[taskId] || {};
      const updated = {
        ...current,
        [field]: value,
        isDirty: true,
      };

      return {
        ...prev,
        [taskId]: updated,
      };
    });
  };

  // Actively selecting an owner immediately saves and updates eligibility
  const handleOwnerSelect = async (taskId, selectedOwnerId) => {
    const numericOwnerId = (selectedOwnerId && !isNaN(parseInt(selectedOwnerId, 10)))
      ? parseInt(selectedOwnerId, 10)
      : null;
    
    // Update local state first
    handleFieldChange(taskId, 'owner_id', selectedOwnerId || '');

    // Save to backend immediately so the ambiguous/missing flag clears and Approve is unlocked
    try {
      setSavingTaskId(taskId);
      const res = await meetingApi.updateTask(taskId, {
        owner_id: numericOwnerId,
      });

      // Update task in state with backend-evaluated eligibility
      setTasks((prev) =>
        prev.map((t) => (t.id === taskId ? { ...t, ...res.task } : t))
      );

      // Re-sync edit buffer
      setTaskEdits((prev) => ({
        ...prev,
        [taskId]: {
          ...prev[taskId],
          owner_id: res.task.owner_id ? String(res.task.owner_id) : '',
          isDirty: false,
        },
      }));

      // Refresh review summary stats from server
      try {
        const summary = await meetingApi.getReviewSummary(id);
        setStats((prev) => ({ ...prev, ...summary }));
      } catch {
        // Fallback: local recalculation
      }

      showNotification('Task owner assigned and eligibility updated.', 'success');
    } catch (err) {
      console.error('Failed to assign owner:', err);
      showNotification(err.apiError?.message || 'Failed to assign owner.', 'error');
    } finally {
      setSavingTaskId(null);
    }
  };

  // Immediate toggle for acknowledgements (conditional_ack, no_deadline_ack)
  const handleToggleAck = async (taskId, field, value) => {
    try {
      setSavingTaskId(taskId);
      const res = await meetingApi.updateTask(taskId, {
        [field]: value,
      });

      setTasks((prev) =>
        prev.map((t) => (t.id === taskId ? { ...t, ...res.task } : t))
      );

      setTaskEdits((prev) => ({
        ...prev,
        [taskId]: {
          ...prev[taskId],
          [field]: value,
        },
      }));

      try {
        const summary = await meetingApi.getReviewSummary(id);
        setStats((prev) => ({ ...prev, ...summary }));
      } catch {
        // Fallback
      }

      showNotification('Requirement acknowledgement updated.', 'success');
    } catch (err) {
      console.error('Failed to update acknowledgement:', err);
      showNotification(err.apiError?.message || 'Failed to update requirement acknowledgement.', 'error');
    } finally {
      setSavingTaskId(null);
    }
  };

  // Save manual inline edits (title, description, due date, priority)
  const handleSaveTask = async (taskId) => {
    const edit = taskEdits[taskId];
    if (!edit) return;

    try {
      setSavingTaskId(taskId);
      const payload = {
        title: edit.title,
        description: edit.description,
        due_date: edit.due_date || null,
        priority: edit.priority,
        conditional: edit.conditional,
      };

      if (edit.owner_id && !isNaN(parseInt(edit.owner_id, 10))) {
        payload.owner_id = parseInt(edit.owner_id, 10);
      } else if (edit.owner_id === '') {
        payload.owner_id = null;
      }

      const res = await meetingApi.updateTask(taskId, payload);

      setTasks((prev) =>
        prev.map((t) => (t.id === taskId ? { ...t, ...res.task } : t))
      );

      setTaskEdits((prev) => ({
        ...prev,
        [taskId]: {
          ...prev[taskId],
          isDirty: false,
        },
      }));

      try {
        const summary = await meetingApi.getReviewSummary(id);
        setStats((prev) => ({ ...prev, ...summary }));
      } catch {
        // Fallback
      }

      showNotification('Task changes saved successfully.', 'success');
    } catch (err) {
      console.error('Failed to save task:', err);
      showNotification(err.apiError?.message || 'Failed to save task changes.', 'error');
    } finally {
      setSavingTaskId(null);
    }
  };

  // Approve a single task
  const handleApproveTask = async (task) => {
    const edit = taskEdits[task.id];
    
    // Check if task is eligible
    if (task.eligibility && !task.eligibility.eligible) {
      showNotification(`Cannot approve: ${task.eligibility.reason || 'Approval requirements not met.'}`, 'error');
      return;
    }

    try {
      setApprovingTaskId(task.id);

      // If user has unsaved title/date edits, save them first
      if (edit?.isDirty) {
        await meetingApi.updateTask(task.id, {
          title: edit.title,
          description: edit.description,
          due_date: edit.due_date || null,
          priority: edit.priority,
          conditional: edit.conditional,
          ...(edit.owner_id && !isNaN(parseInt(edit.owner_id, 10)) ? { owner_id: parseInt(edit.owner_id, 10) } : {}),
        });
      }

      const res = await meetingApi.approveTask(task.id);

      setTasks((prev) =>
        prev.map((t) => (t.id === task.id ? { ...t, ...res.task } : t))
      );

      if (res.meeting_status) {
        setMeeting((prev) => ({ ...prev, status: res.meeting_status }));
      }

      // Refresh stats from backend
      try {
        const summary = await meetingApi.getReviewSummary(id);
        setStats((prev) => ({ ...prev, ...summary }));
      } catch {
        // Fallback
      }

      if (res.is_meeting_resolved) {
        showNotification('All tasks resolved! Meeting reviewed & outbound webhook fired.', 'success');
      } else {
        showNotification(`Task "${task.title}" approved and assigned.`, 'success');
      }
    } catch (err) {
      console.error('Failed to approve task:', err);
      showNotification(err.apiError?.message || 'Failed to approve task.', 'error');
    } finally {
      setApprovingTaskId(null);
    }
  };

  // Reject a single task
  const handleRejectTask = async (task) => {
    const reason = window.prompt('Enter rejection reason (optional):', '');
    if (reason === null) return; // User cancelled prompt

    try {
      setRejectingTaskId(task.id);
      const res = await meetingApi.rejectTask(task.id, reason);

      setTasks((prev) =>
        prev.map((t) => (t.id === task.id ? { ...t, ...res.task } : t))
      );

      if (res.meeting_status) {
        setMeeting((prev) => ({ ...prev, status: res.meeting_status }));
      }

      try {
        const summary = await meetingApi.getReviewSummary(id);
        setStats((prev) => ({ ...prev, ...summary }));
      } catch {
        // Fallback
      }

      showNotification(`Task "${task.title}" rejected.`, 'info');
    } catch (err) {
      console.error('Failed to reject task:', err);
      showNotification(err.apiError?.message || 'Failed to reject task.', 'error');
    } finally {
      setRejectingTaskId(null);
    }
  };

  // Bulk Approve All (strictly approves eligible tasks and skips ineligible cards)
  const handleApproveAll = async () => {
    try {
      setIsApprovingAll(true);
      const res = await meetingApi.approveAll(meeting.id);

      if (res.tasks) {
        setTasks(res.tasks);
      }
      if (res.meeting_status) {
        setMeeting((prev) => ({ ...prev, status: res.meeting_status }));
      }

      try {
        const summary = await meetingApi.getReviewSummary(id);
        setStats((prev) => ({ ...prev, ...summary }));
      } catch {
        // Fallback
      }

      if (res.skipped_count > 0) {
        showNotification(
          `Approved ${res.approved_count} task(s). Skipped ${res.skipped_count} card(s) that require review or an assigned owner.`,
          'info'
        );
      } else {
        showNotification(`Approved all ${res.approved_count} eligible tasks!`, 'success');
      }
    } catch (err) {
      console.error('Failed to approve all:', err);
      showNotification(err.apiError?.message || 'Failed to execute Approve All.', 'error');
    } finally {
      setIsApprovingAll(false);
    }
  };

  // Filter tasks for list view
  const filteredTasks = useMemo(() => {
    return tasks.filter((task) => {
      const isPending = ['pending_approval', 'detected'].includes(task.status);
      if (activeFilter === 'pending') return isPending;
      if (activeFilter === 'eligible') return isPending && (task.eligibility ? task.eligibility.eligible : true);
      if (activeFilter === 'needs_attention') return isPending && (task.eligibility ? !task.eligibility.eligible : false);
      if (activeFilter === 'approved') return ['approved', 'assigned', 'in_progress', 'completed'].includes(task.status);
      if (activeFilter === 'rejected') return task.status === 'rejected';
      if (activeFilter === 'ambiguous') return task.owner_ambiguous || task.owner_state === 'ambiguous' || (!task.owner_id && isPending);
      return true;
    });
  }, [tasks, activeFilter]);

  // Ambiguous count for banner alert
  const ambiguousCount = useMemo(() => {
    return tasks.filter((t) => ['pending_approval', 'detected'].includes(t.status) && (t.owner_ambiguous || t.owner_state === 'ambiguous')).length;
  }, [tasks]);

  // Pending eligible count for Approve All
  const eligibleToApproveCount = useMemo(() => {
    if (typeof stats.eligible === 'number') {
      return stats.eligible;
    }
    return tasks.filter((t) => ['pending_approval', 'detected'].includes(t.status) && t.eligibility?.eligible).length;
  }, [tasks, stats.eligible]);

  if (loading) {
    return (
      <div className="min-h-[70vh] flex flex-col items-center justify-center space-y-4">
        <Loader2 className="w-10 h-10 text-indigo-500 animate-spin" />
        <p className="text-sm text-slate-400 font-medium">Loading meeting extraction review...</p>
      </div>
    );
  }

  if (error || !meeting) {
    return (
      <div className="p-8 rounded-2xl glass-panel border border-rose-500/30 bg-rose-950/20 text-center space-y-4">
        <AlertCircle className="w-12 h-12 text-rose-400 mx-auto" />
        <h2 className="text-xl font-bold text-white">Unable to Load Review Screen</h2>
        <p className="text-sm text-rose-300">{error || 'Meeting not found or access unauthorized.'}</p>
        <Link
          to="/admin"
          className="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-slate-800 text-slate-200 text-sm font-medium hover:bg-slate-700"
        >
          <ChevronLeft className="w-4 h-4" /> Back to Admin
        </Link>
      </div>
    );
  }

  return (
    <div className="space-y-6 pb-16">
      {/* Toast Notification */}
      {notification && (
        <div
          className={`fixed bottom-6 right-6 z-50 flex items-center gap-3 px-4 py-3 rounded-xl shadow-2xl border text-sm font-medium transition-all transform animate-in slide-in-from-bottom-5 ${
            notification.type === 'error'
              ? 'bg-rose-950/95 text-rose-200 border-rose-500/50 shadow-rose-950/50'
              : notification.type === 'info'
              ? 'bg-sky-950/95 text-sky-200 border-sky-500/50 shadow-sky-950/50'
              : 'bg-emerald-950/95 text-emerald-200 border-emerald-500/50 shadow-emerald-950/50'
          }`}
        >
          {notification.type === 'error' ? (
            <AlertCircle className="w-5 h-5 text-rose-400 shrink-0" />
          ) : notification.type === 'info' ? (
            <AlertTriangle className="w-5 h-5 text-sky-400 shrink-0" />
          ) : (
            <BadgeCheck className="w-5 h-5 text-emerald-400 shrink-0" />
          )}
          <span>{notification.message}</span>
        </div>
      )}

      {/* Top Header Card */}
      <div className="p-6 rounded-2xl glass-panel border border-slate-800/80 bg-gradient-to-r from-slate-900 via-indigo-950/30 to-slate-900 space-y-4">
        <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div className="space-y-1">
            <div className="flex items-center gap-2">
              <Link
                to="/admin"
                className="text-xs font-medium text-slate-400 hover:text-indigo-400 flex items-center gap-1 transition-colors"
              >
                <ChevronLeft className="w-3.5 h-3.5" /> Back to Dashboard
              </Link>
              <span className="text-slate-600">•</span>
              <span className="text-xs font-semibold text-indigo-400 uppercase tracking-wider">
                Action Extraction Review
              </span>
            </div>
            <div className="flex items-center gap-3 flex-wrap">
              <h1 className="text-2xl font-bold text-white tracking-tight">{meeting.title}</h1>
              {meeting.status === 'reviewed' ? (
                <span className="px-3 py-1 text-xs font-bold rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 flex items-center gap-1.5 shadow-sm shadow-emerald-500/20">
                  <BadgeCheck className="w-3.5 h-3.5" /> Reviewed & Dispatched
                </span>
              ) : (
                <span className="px-3 py-1 text-xs font-bold rounded-full bg-amber-500/20 text-amber-300 border border-amber-500/40 flex items-center gap-1.5 animate-pulse">
                  <Clock className="w-3.5 h-3.5" /> Pending Review
                </span>
              )}
            </div>
            <div className="flex items-center gap-4 text-xs text-slate-400 flex-wrap">
              <span className="flex items-center gap-1">
                <Calendar className="w-3.5 h-3.5 text-indigo-400" />
                {meeting.meeting_date || 'N/A'} ({meeting.timezone})
              </span>
              <span className="flex items-center gap-1">
                <User className="w-3.5 h-3.5 text-indigo-400" />
                Uploaded by: <strong className="text-slate-300">{meeting.creator?.name || 'Admin'}</strong>
              </span>
              <span className="px-2 py-0.5 rounded bg-slate-800 text-[11px] text-slate-300 font-mono">
                Source: {meeting.source}
              </span>
            </div>
          </div>

          {/* Action Bar */}
          <div className="flex items-center gap-3 self-start lg:self-center">
            <button
              onClick={handleApproveAll}
              disabled={isApprovingAll || eligibleToApproveCount === 0}
              className={`px-5 py-2.5 rounded-xl font-semibold text-sm flex items-center gap-2 shadow-lg transition-all ${
                eligibleToApproveCount === 0 || isApprovingAll
                  ? 'bg-slate-800 text-slate-500 cursor-not-allowed border border-slate-700/50'
                  : 'bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white shadow-emerald-600/30 ring-1 ring-white/20 active:scale-95'
              }`}
              title={
                eligibleToApproveCount > 0
                  ? `Approves ${eligibleToApproveCount} eligible task(s)`
                  : 'No eligible tasks to approve'
              }
            >
              {isApprovingAll ? (
                <Loader2 className="w-4 h-4 animate-spin" />
              ) : (
                <CheckCheck className="w-4 h-4" />
              )}
              <span>Approve All Eligible ({eligibleToApproveCount})</span>
            </button>
          </div>
        </div>

        {/* Ambiguity Alert Callout Banner */}
        {ambiguousCount > 0 && (
          <div className="p-3.5 rounded-xl bg-rose-950/40 border border-rose-500/40 text-rose-200 flex items-start gap-3 text-xs">
            <AlertTriangle className="w-5 h-5 text-rose-400 shrink-0 mt-0.5" />
            <div>
              <span className="font-bold text-rose-300">Action Required: {ambiguousCount} task(s) have an ambiguous or missing owner.</span>
              <p className="text-rose-300/80 mt-0.5">
                The AI detected a name with multiple roster matches or unresolved owner. The <strong>Approve</strong> button is disabled for these cards until you select the intended assignee from the dropdown. "Approve All Eligible" will automatically skip these tasks until resolved.
              </p>
            </div>
          </div>
        )}

        {/* Statistics Bar */}
        <div className="grid grid-cols-2 sm:grid-cols-6 gap-3 pt-2 border-t border-slate-800/60">
          <button
            onClick={() => setActiveFilter('all')}
            className={`p-2.5 rounded-xl border text-left transition-all ${
              activeFilter === 'all'
                ? 'bg-indigo-600/20 border-indigo-500/50 text-indigo-200 ring-1 ring-indigo-500/30'
                : 'bg-slate-900/60 border-slate-800 text-slate-400 hover:text-slate-200 hover:bg-slate-800/40'
            }`}
          >
            <div className="text-[11px] font-medium uppercase tracking-wider">Total Tasks</div>
            <div className="text-xl font-bold text-white mt-0.5">{stats.total ?? tasks.length}</div>
          </button>

          <button
            onClick={() => setActiveFilter('pending')}
            className={`p-2.5 rounded-xl border text-left transition-all ${
              activeFilter === 'pending'
                ? 'bg-amber-600/20 border-amber-500/50 text-amber-200 ring-1 ring-amber-500/30'
                : 'bg-slate-900/60 border-slate-800 text-slate-400 hover:text-slate-200 hover:bg-slate-800/40'
            }`}
          >
            <div className="text-[11px] font-medium uppercase tracking-wider">Pending Review</div>
            <div className="text-xl font-bold text-amber-400 mt-0.5">{stats.pending ?? 0}</div>
          </button>

          <button
            onClick={() => setActiveFilter('eligible')}
            className={`p-2.5 rounded-xl border text-left transition-all ${
              activeFilter === 'eligible'
                ? 'bg-emerald-600/20 border-emerald-500/50 text-emerald-200 ring-1 ring-emerald-500/30'
                : 'bg-slate-900/60 border-slate-800 text-slate-400 hover:text-slate-200 hover:bg-slate-800/40'
            }`}
          >
            <div className="text-[11px] font-medium uppercase tracking-wider flex items-center justify-between">
              <span>Ready / Eligible</span>
              {eligibleToApproveCount > 0 && <span className="w-2 h-2 rounded-full bg-emerald-500" />}
            </div>
            <div className="text-xl font-bold text-emerald-400 mt-0.5">{eligibleToApproveCount}</div>
          </button>

          <button
            onClick={() => setActiveFilter('needs_attention')}
            className={`p-2.5 rounded-xl border text-left transition-all ${
              activeFilter === 'needs_attention'
                ? 'bg-rose-600/20 border-rose-500/50 text-rose-200 ring-1 ring-rose-500/30'
                : 'bg-slate-900/60 border-slate-800 text-slate-400 hover:text-slate-200 hover:bg-slate-800/40'
            }`}
          >
            <div className="text-[11px] font-medium uppercase tracking-wider flex items-center justify-between">
              <span>Needs Attention</span>
              {(stats.needs_owner || 0) + (stats.ambiguous_owner || 0) > 0 && (
                <span className="w-2 h-2 rounded-full bg-rose-500 animate-ping" />
              )}
            </div>
            <div className="text-xl font-bold text-rose-400 mt-0.5">
              {(stats.needs_owner || 0) + (stats.ambiguous_owner || 0) || ambiguousCount}
            </div>
          </button>

          <button
            onClick={() => setActiveFilter('approved')}
            className={`p-2.5 rounded-xl border text-left transition-all ${
              activeFilter === 'approved'
                ? 'bg-teal-600/20 border-teal-500/50 text-teal-200 ring-1 ring-teal-500/30'
                : 'bg-slate-900/60 border-slate-800 text-slate-400 hover:text-slate-200 hover:bg-slate-800/40'
            }`}
          >
            <div className="text-[11px] font-medium uppercase tracking-wider">Approved</div>
            <div className="text-xl font-bold text-teal-400 mt-0.5">{stats.approved ?? 0}</div>
          </button>

          <button
            onClick={() => setActiveFilter('rejected')}
            className={`p-2.5 rounded-xl border text-left transition-all ${
              activeFilter === 'rejected'
                ? 'bg-slate-700/40 border-slate-600 text-slate-200 ring-1 ring-slate-500/30'
                : 'bg-slate-900/60 border-slate-800 text-slate-400 hover:text-slate-200 hover:bg-slate-800/40'
            }`}
          >
            <div className="text-[11px] font-medium uppercase tracking-wider">Rejected</div>
            <div className="text-xl font-bold text-slate-400 mt-0.5">{stats.rejected ?? 0}</div>
          </button>
        </div>
      </div>

      {/* Main Review Grid: Task Cards (Left) + Full Transcript Panel (Right) */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        {/* Left Column: Extracted Task Cards (7 cols) */}
        <div className="lg:col-span-7 space-y-4">
          <div className="flex items-center justify-between px-1">
            <h2 className="text-base font-bold text-white flex items-center gap-2">
              <FileCheck2 className="w-5 h-5 text-indigo-400" />
              Extracted Action Items
              <span className="text-xs font-semibold px-2 py-0.5 rounded-full bg-slate-800 text-slate-400">
                {filteredTasks.length} {filteredTasks.length === 1 ? 'card' : 'cards'}
              </span>
            </h2>
            <div className="text-xs text-slate-400">
              {activeFilter !== 'all' && (
                <button
                  onClick={() => setActiveFilter('all')}
                  className="text-indigo-400 hover:underline"
                >
                  Show all tasks
                </button>
              )}
            </div>
          </div>

          {filteredTasks.length === 0 ? (
            <div className="p-12 text-center rounded-2xl glass-card border border-slate-800 space-y-3">
              <FileCheck2 className="w-10 h-10 text-slate-600 mx-auto" />
              <div className="text-sm font-semibold text-slate-300">No tasks in this filter view</div>
              <p className="text-xs text-slate-500">Select a different tab above to view other tasks.</p>
            </div>
          ) : (
            filteredTasks.map((task, index) => {
              const edit = taskEdits[task.id] || {
                title: task.title,
                description: task.description,
                owner_id: task.owner_id ? String(task.owner_id) : '',
                due_date: task.due_date || '',
                priority: task.priority || 'medium',
                conditional: Boolean(task.conditional),
                conditional_ack: Boolean(task.conditional_ack),
                no_deadline_ack: Boolean(task.no_deadline_ack),
                isDirty: false,
              };

              const isAmbiguous = task.owner_ambiguous || task.owner_state === 'ambiguous';
              const isUnmatched = task.owner_state === 'unmatched';
              const isLowDeadlineConfidence = task.deadline_confidence !== null && task.deadline_confidence < 0.7;
              const isConditional = Boolean(task.conditional);
              const isApproved = ['approved', 'assigned', 'in_progress', 'completed'].includes(task.status);
              const isRejected = task.status === 'rejected';
              const isPending = ['pending_approval', 'detected'].includes(task.status);
              const isEligible = Boolean(task.eligibility?.eligible);
              const blockers = task.eligibility?.blockers || [];
              const canApprove = isPending && isEligible;

              return (
                <div
                  key={task.id}
                  onMouseEnter={() => setHighlightedSourceText(task.source_text)}
                  onMouseLeave={() => setHighlightedSourceText(null)}
                  className={`rounded-2xl transition-all p-5 space-y-4 ${
                    isAmbiguous || isUnmatched
                      ? 'border-2 border-rose-500/90 bg-gradient-to-b from-rose-950/30 via-slate-900/90 to-slate-900/70 shadow-xl shadow-rose-950/30 ring-1 ring-rose-500/40'
                      : isApproved
                      ? 'border border-emerald-500/40 bg-slate-900/60 opacity-90'
                      : isRejected
                      ? 'border border-slate-800 bg-slate-950/60 opacity-60'
                      : isEligible
                      ? 'glass-card border-emerald-500/30 hover:border-emerald-500/50 shadow-md'
                      : 'glass-card border-amber-500/40 hover:border-amber-500/60 shadow-md'
                  }`}
                >
                  {/* Card Header & Flags */}
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-2 flex-wrap">
                      <span className="w-6 h-6 rounded-lg bg-slate-800 text-slate-300 font-mono text-xs font-bold flex items-center justify-center">
                        #{index + 1}
                      </span>

                      {/* Ambiguous Owner Flag Badge */}
                      {isAmbiguous && (
                        <span className="px-2.5 py-1 rounded-full text-xs font-bold bg-rose-500/20 text-rose-300 border border-rose-500/50 flex items-center gap-1.5 shadow-sm shadow-rose-950">
                          <AlertTriangle className="w-3.5 h-3.5 text-rose-400" />
                          Ambiguous Owner ({task.owner_name_raw || 'Multiple Matches'})
                        </span>
                      )}

                      {/* Unmatched Owner Badge */}
                      {isUnmatched && (
                        <span className="px-2.5 py-1 rounded-full text-xs font-bold bg-rose-500/20 text-rose-300 border border-rose-500/50 flex items-center gap-1.5 shadow-sm shadow-rose-950">
                          <AlertTriangle className="w-3.5 h-3.5 text-rose-400" />
                          Unmatched Owner ({task.owner_name_raw || 'Unknown'})
                        </span>
                      )}

                      {/* Conditional Badge */}
                      {isConditional && (
                        <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-purple-500/20 text-purple-300 border border-purple-500/40 flex items-center gap-1">
                          <HelpCircle className="w-3 h-3 text-purple-400" />
                          Conditional
                        </span>
                      )}

                      {/* Readiness Badges */}
                      {isPending && isEligible && (
                        <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 flex items-center gap-1">
                          <Check className="w-3 h-3 text-emerald-400" />
                          Ready for Approval
                        </span>
                      )}

                      {isPending && !isEligible && (
                        <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/20 text-amber-300 border border-amber-500/40 flex items-center gap-1">
                          <AlertCircle className="w-3 h-3 text-amber-400" />
                          Action Required
                        </span>
                      )}

                      {/* Status Badges */}
                      {isApproved && (
                        <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 flex items-center gap-1">
                          <BadgeCheck className="w-3.5 h-3.5 text-emerald-400" />
                          {task.status.toUpperCase()}
                        </span>
                      )}

                      {isRejected && (
                        <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-500/20 text-rose-300 border border-rose-500/40 flex items-center gap-1">
                          <Ban className="w-3 h-3 text-rose-400" />
                          REJECTED
                        </span>
                      )}
                    </div>

                    {/* Priority Selector Pill */}
                    <div className="shrink-0">
                      <select
                        disabled={isApproved || isRejected}
                        value={edit.priority}
                        onChange={(e) => handleFieldChange(task.id, 'priority', e.target.value)}
                        className={`text-xs font-bold px-2.5 py-1 rounded-lg border focus:outline-none transition-all cursor-pointer ${
                          edit.priority === 'high'
                            ? 'bg-rose-500/20 text-rose-300 border-rose-500/40'
                            : edit.priority === 'medium'
                            ? 'bg-amber-500/20 text-amber-300 border-amber-500/40'
                            : 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40'
                        }`}
                      >
                        <option value="high" className="bg-slate-900 text-rose-300">High Priority</option>
                        <option value="medium" className="bg-slate-900 text-amber-300">Medium Priority</option>
                        <option value="low" className="bg-slate-900 text-emerald-300">Low Priority</option>
                      </select>
                    </div>
                  </div>

                  {/* Inline Editable Title */}
                  <div className="space-y-1">
                    <label className="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">
                      Task Title
                    </label>
                    <input
                      type="text"
                      disabled={isApproved || isRejected}
                      value={edit.title}
                      onChange={(e) => handleFieldChange(task.id, 'title', e.target.value)}
                      placeholder="Task Title..."
                      className="w-full px-3.5 py-2 rounded-xl bg-slate-950/60 border border-slate-700/80 text-sm font-semibold text-white focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30 transition-all placeholder:text-slate-600"
                    />
                  </div>

                  {/* Inline Editable Description */}
                  <div className="space-y-1">
                    <label className="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">
                      Description
                    </label>
                    <textarea
                      rows={2}
                      disabled={isApproved || isRejected}
                      value={edit.description || ''}
                      onChange={(e) => handleFieldChange(task.id, 'description', e.target.value)}
                      placeholder="Additional task description or context..."
                      className="w-full px-3.5 py-2 rounded-xl bg-slate-950/60 border border-slate-700/80 text-xs text-slate-200 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30 transition-all placeholder:text-slate-600 resize-y"
                    />
                  </div>

                  {/* Owner Dropdown & Due Date Fields */}
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    {/* Owner Dropdown */}
                    <div className="space-y-1">
                      <label className="text-[11px] font-semibold text-slate-400 uppercase tracking-wider flex items-center justify-between">
                        <span>Assignee / Owner</span>
                        {(isAmbiguous || isUnmatched || !edit.owner_id) && (
                          <span className="text-[10px] text-rose-400 font-bold">Required</span>
                        )}
                      </label>
                      <div className="relative">
                        <select
                          disabled={isApproved || isRejected}
                          value={edit.owner_id}
                          onChange={(e) => handleOwnerSelect(task.id, e.target.value)}
                          className={`w-full px-3 py-2 rounded-xl text-xs font-medium focus:outline-none transition-all cursor-pointer ${
                            isAmbiguous || isUnmatched
                              ? 'bg-rose-950/50 border-2 border-rose-500 text-rose-100 font-semibold focus:border-rose-400 focus:ring-2 focus:ring-rose-500/40'
                              : 'bg-slate-950/60 border border-slate-700/80 text-slate-200 focus:border-indigo-500'
                          }`}
                        >
                          <option value="" className="bg-slate-900 text-slate-500">
                            {isAmbiguous
                              ? '⚠️ Ambiguous owner: select intended assignee...'
                              : isUnmatched
                              ? '⚠️ Unmatched owner: select assignee...'
                              : 'Select an owner...'}
                          </option>
                          {orgUsers.map((u) => (
                            <option key={u.id} value={u.id} className="bg-slate-900 text-slate-200">
                              {u.name} ({u.email}) — {u.role}
                            </option>
                          ))}
                        </select>
                      </div>

                      {/* Ambiguous or Unmatched Owner Hint */}
                      {isAmbiguous && (
                        <div className="text-[11px] text-rose-300 font-medium flex items-center gap-1.5 pt-0.5">
                          <AlertTriangle className="w-3.5 h-3.5 text-rose-400 shrink-0" />
                          <span>
                            AI heard <strong>"{task.owner_name_raw || 'Ali'}"</strong> — multiple roster matches. Please select intended assignee.
                          </span>
                        </div>
                      )}
                      {isUnmatched && (
                        <div className="text-[11px] text-rose-300 font-medium flex items-center gap-1.5 pt-0.5">
                          <AlertTriangle className="w-3.5 h-3.5 text-rose-400 shrink-0" />
                          <span>
                            AI heard <strong>"{task.owner_name_raw}"</strong> — no roster match found.
                          </span>
                        </div>
                      )}
                    </div>

                    {/* Due Date Picker */}
                    <div className="space-y-1">
                      <label className="text-[11px] font-semibold text-slate-400 uppercase tracking-wider flex items-center justify-between">
                        <span>Due Date</span>
                        {isLowDeadlineConfidence && (
                          <span className="text-[10px] text-amber-400 font-bold">Low Confidence</span>
                        )}
                      </label>
                      <input
                        type="date"
                        disabled={isApproved || isRejected}
                        value={edit.due_date}
                        onChange={(e) => handleFieldChange(task.id, 'due_date', e.target.value)}
                        className={`w-full px-3 py-2 rounded-xl text-xs font-medium focus:outline-none transition-all ${
                          isLowDeadlineConfidence
                            ? 'bg-amber-950/30 border-2 border-amber-500/80 text-amber-200 ring-1 ring-amber-500/30'
                            : 'bg-slate-950/60 border border-slate-700/80 text-slate-200 focus:border-indigo-500'
                        }`}
                      />

                      {/* Low Deadline Confidence Hint */}
                      {isLowDeadlineConfidence && task.deadline_phrase && (
                        <div className="text-[11px] text-amber-300 font-medium flex items-center gap-1.5 pt-0.5">
                          <Clock className="w-3.5 h-3.5 text-amber-400 shrink-0" />
                          <span>
                            AI detected phrase: <em>"{task.deadline_phrase}"</em>
                          </span>
                        </div>
                      )}
                    </div>
                  </div>

                  {/* Inline Blockers & Acknowledgements Box */}
                  {isPending && !isEligible && (
                    <div className="p-3.5 rounded-xl bg-amber-950/30 border border-amber-500/40 text-xs space-y-2">
                      <div className="flex items-center gap-1.5 font-bold text-amber-300">
                        <AlertTriangle className="w-4 h-4 text-amber-400 shrink-0" />
                        <span>Approval Requirements</span>
                      </div>
                      <div className="space-y-1.5 pl-5">
                        {blockers.includes('OWNER_REQUIRED') && (
                          <p className="text-amber-200">• Assignee missing: Select a team member from the dropdown above.</p>
                        )}
                        {blockers.includes('OWNER_AMBIGUOUS') && (
                          <p className="text-rose-300 font-semibold">• Ambiguous owner: Select the intended person from the dropdown.</p>
                        )}
                        {blockers.includes('OWNER_NOT_IN_ORG') && (
                          <p className="text-rose-300">• Selected owner is not a member of this organization.</p>
                        )}
                        {blockers.includes('OWNER_INACTIVE') && (
                          <p className="text-rose-300">• Selected owner account is deactivated. Please reassign.</p>
                        )}
                        {blockers.includes('TITLE_REQUIRED') && (
                          <p className="text-rose-300">• Task title cannot be empty.</p>
                        )}
                        {blockers.includes('CONDITIONAL_UNACKNOWLEDGED') && (
                          <div className="flex items-center gap-2 pt-1 text-purple-300 font-medium">
                            <input
                              type="checkbox"
                              id={`cond-ack-${task.id}`}
                              checked={Boolean(task.conditional_ack)}
                              onChange={(e) => handleToggleAck(task.id, 'conditional_ack', e.target.checked)}
                              className="rounded border-purple-500/50 bg-slate-900 text-purple-600 focus:ring-purple-500 cursor-pointer"
                            />
                            <label htmlFor={`cond-ack-${task.id}`} className="cursor-pointer">
                              Acknowledge conditional item (unblocks approval)
                            </label>
                          </div>
                        )}
                        {blockers.includes('NO_DEADLINE_UNACKNOWLEDGED') && (
                          <div className="flex items-center gap-2 pt-1 text-amber-300 font-medium">
                            <input
                              type="checkbox"
                              id={`nodeadline-ack-${task.id}`}
                              checked={Boolean(task.no_deadline_ack)}
                              onChange={(e) => handleToggleAck(task.id, 'no_deadline_ack', e.target.checked)}
                              className="rounded border-amber-500/50 bg-slate-900 text-amber-600 focus:ring-amber-500 cursor-pointer"
                            />
                            <label htmlFor={`nodeadline-ack-${task.id}`} className="cursor-pointer">
                              Acknowledge task has no deadline (unblocks approval)
                            </label>
                          </div>
                        )}
                      </div>
                    </div>
                  )}

                  {/* source_text Block — Exactly beneath each card */}
                  {task.source_text && (
                    <div className="p-3 rounded-xl bg-slate-950/80 border-l-4 border-indigo-500/80 text-xs space-y-1">
                      <div className="text-[10px] font-bold uppercase tracking-wider text-indigo-400 flex items-center gap-1">
                        <Quote className="w-3 h-3" /> Source Sentence from Transcript
                      </div>
                      <p className="text-slate-300 italic font-mono text-[11px] leading-relaxed">
                        "{task.source_text}"
                      </p>
                    </div>
                  )}

                  {/* Card Action Buttons */}
                  <div className="pt-2 border-t border-slate-800/60 flex items-center justify-between gap-3 flex-wrap">
                    {/* Left: Save inline edits */}
                    <div>
                      {edit.isDirty && isPending && (
                        <button
                          onClick={() => handleSaveTask(task.id)}
                          disabled={savingTaskId === task.id}
                          className="px-3 py-1.5 rounded-lg bg-indigo-600/20 text-indigo-300 border border-indigo-500/40 text-xs font-semibold flex items-center gap-1.5 hover:bg-indigo-600/30 transition-all"
                        >
                          {savingTaskId === task.id ? (
                            <Loader2 className="w-3.5 h-3.5 animate-spin" />
                          ) : (
                            <Save className="w-3.5 h-3.5" />
                          )}
                          <span>Save Changes</span>
                        </button>
                      )}
                    </div>

                    {/* Right: Approve / Reject Actions */}
                    <div className="flex items-center gap-2 ml-auto">
                      {isPending && (
                        <>
                          <button
                            onClick={() => handleRejectTask(task)}
                            disabled={rejectingTaskId === task.id}
                            className="px-3.5 py-1.5 rounded-xl bg-rose-500/10 hover:bg-rose-500/20 text-rose-300 border border-rose-500/30 text-xs font-semibold flex items-center gap-1.5 transition-all"
                            title="Reject and discard this extracted task"
                          >
                            {rejectingTaskId === task.id ? (
                              <Loader2 className="w-3.5 h-3.5 animate-spin" />
                            ) : (
                              <X className="w-3.5 h-3.5" />
                            )}
                            <span>Reject</span>
                          </button>

                          <button
                            onClick={() => handleApproveTask(task)}
                            disabled={!canApprove || approvingTaskId === task.id}
                            className={`px-4 py-1.5 rounded-xl text-xs font-bold flex items-center gap-1.5 shadow-sm transition-all ${
                              canApprove
                                ? 'bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white shadow-emerald-600/20 cursor-pointer active:scale-95'
                                : 'bg-slate-800/80 text-slate-500 border border-slate-700/50 cursor-not-allowed'
                            }`}
                            title={
                              canApprove
                                ? 'Approve and assign this task'
                                : task.eligibility?.reason || 'Approval requirements not met'
                            }
                          >
                            {approvingTaskId === task.id ? (
                              <Loader2 className="w-3.5 h-3.5 animate-spin" />
                            ) : (
                              <Check className="w-3.5 h-3.5" />
                            )}
                            <span>{canApprove ? 'Approve' : (task.eligibility?.reason ? 'Blocked' : 'Ineligible')}</span>
                          </button>
                        </>
                      )}

                      {isApproved && (
                        <div className="text-xs text-emerald-400 font-semibold flex items-center gap-1">
                          <BadgeCheck className="w-4 h-4" />
                          Approved & Assigned to {task.owner?.name || 'Owner'}
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              );
            })
          )}
        </div>

        {/* Right Column: Scrollable Full Transcript Panel (5 cols) */}
        <div className="lg:col-span-5 sticky top-6 space-y-4">
          <div className="glass-panel rounded-2xl border border-slate-800/80 p-5 space-y-4 shadow-xl">
            <div className="flex items-center justify-between border-b border-slate-800/80 pb-3">
              <div className="flex items-center gap-2">
                <FileText className="w-5 h-5 text-indigo-400" />
                <h3 className="text-sm font-bold text-white">Full Meeting Transcript</h3>
              </div>
              <span className="text-[11px] text-slate-400 font-mono">
                {meeting.transcript ? `${meeting.transcript.length} chars` : 'No transcript'}
              </span>
            </div>

            {/* Transcript Search Bar */}
            <div className="relative">
              <Search className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2" />
              <input
                type="text"
                placeholder="Search words in transcript..."
                value={transcriptSearch}
                onChange={(e) => setTranscriptSearch(e.target.value)}
                className="w-full pl-9 pr-3.5 py-1.5 rounded-xl bg-slate-950/80 border border-slate-700/80 text-xs text-slate-200 placeholder:text-slate-600 focus:outline-none focus:border-indigo-500"
              />
            </div>

            {/* Meeting Summary Box */}
            {meeting.summary && (
              <div className="p-3.5 rounded-xl bg-indigo-950/30 border border-indigo-500/30 space-y-1">
                <div className="text-[11px] font-bold text-indigo-300 uppercase tracking-wider flex items-center gap-1.5">
                  <Sparkles className="w-3.5 h-3.5 text-indigo-400" />
                  Meeting Summary
                </div>
                <p className="text-xs text-slate-300 leading-relaxed font-sans">
                  {meeting.summary}
                </p>
              </div>
            )}

            {/* Transcript Text Container */}
            <div className="max-h-[calc(100vh-22rem)] overflow-y-auto pr-2 space-y-3">
              <div className="p-4 rounded-xl bg-slate-950/90 border border-slate-800/80 text-xs text-slate-200 font-mono leading-relaxed whitespace-pre-wrap selection:bg-indigo-500 selection:text-white">
                {meeting.transcript ? (
                  transcriptSearch ? (
                    // Simple highlighting for search terms (escape regex special chars to prevent crash)
                    (() => {
                      const escaped = transcriptSearch.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                      return meeting.transcript.split(new RegExp(`(${escaped})`, 'gi')).map((part, i) =>
                        part.toLowerCase() === transcriptSearch.toLowerCase() ? (
                          <mark key={i} className="bg-amber-400 text-slate-950 font-bold px-0.5 rounded">
                            {part}
                          </mark>
                        ) : (
                          <span key={i}>{part}</span>
                        )
                      );
                    })()
                  ) : highlightedSourceText && meeting.transcript.includes(highlightedSourceText) ? (
                    // Highlight active hovered card source text
                    meeting.transcript.split(highlightedSourceText).map((part, i, arr) => (
                      <React.Fragment key={i}>
                        {part}
                        {i < arr.length - 1 && (
                          <mark className="bg-indigo-600/60 text-white font-bold px-1 py-0.5 rounded border border-indigo-400/50">
                            {highlightedSourceText}
                          </mark>
                        )}
                      </React.Fragment>
                    ))
                  ) : (
                    meeting.transcript
                  )
                ) : (
                  <span className="text-slate-500 italic">No transcript recorded for this meeting.</span>
                )}
              </div>
            </div>

            <div className="text-[11px] text-slate-500 pt-2 border-t border-slate-800/60 flex items-center justify-between">
              <span>Hover over a task card to locate its source text.</span>
              <span className="text-indigo-400 font-mono">Org ID #{meeting.org_id}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};
