import React, { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { executiveApi } from '../services/api';
import {
  Activity,
  CheckCircle2,
  Clock,
  Lock,
  ShieldAlert,
  FileText,
  RefreshCw,
  Loader2,
  AlertTriangle,
  ArrowRight,
  User,
  Calendar,
  AlertOctagon,
  Check,
  ExternalLink,
} from 'lucide-react';

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

export const ExecutiveDashboard = () => {
  const { user } = useAuth();
  const navigate = useNavigate();

  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState(null);
  const [dashboardData, setDashboardData] = useState(null);

  const fetchDashboard = useCallback(async (isManualRefresh = false) => {
    if (isManualRefresh) {
      setRefreshing(true);
    } else {
      setLoading(true);
    }
    setError(null);

    try {
      const res = await executiveApi.dashboard();
      setDashboardData(res.data);
    } catch (err) {
      console.error('Failed to load executive dashboard:', err);
      setError(err?.apiError?.message || 'Failed to load executive metrics.');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => {
    fetchDashboard();
  }, [fetchDashboard]);

  const counts = dashboardData?.counts || {
    active: 0,
    completed_today: 0,
    overdue: 0,
    blocked: 0,
    at_risk: 0,
    awaiting_approval: 0,
  };

  const criticalBlockers = dashboardData?.exceptions?.critical_blockers || [];
  const atRiskTasks = dashboardData?.exceptions?.at_risk || [];
  const awaitingApproval = dashboardData?.exceptions?.awaiting_approval || [];

  return (
    <div className="space-y-8 pb-12">
      {/* Top Banner / Header */}
      <div className="p-6 rounded-2xl glass-panel border border-slate-800/80 bg-gradient-to-r from-slate-900 via-purple-950/20 to-slate-900 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <span className="text-xs font-semibold uppercase tracking-wider text-purple-400">
              Executive View
            </span>
            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-purple-500/10 text-purple-300 border border-purple-500/20">
              Read-Only Org Posture
            </span>
          </div>
          <h1 className="text-2xl font-bold text-white mt-1">Company Health & Exception Metrics</h1>
          <p className="text-sm text-slate-400 mt-1">
            Real-time commitment tracking for {user?.name || 'Executive'}. Computed across all departments.
          </p>
        </div>

        <button
          onClick={() => fetchDashboard(true)}
          disabled={loading || refreshing}
          className="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-medium text-slate-300 hover:text-white bg-slate-800/80 hover:bg-slate-700/80 border border-slate-700/60 transition-all self-start md:self-auto disabled:opacity-50"
        >
          <RefreshCw className={`w-3.5 h-3.5 ${refreshing ? 'animate-spin text-purple-400' : ''}`} />
          {refreshing ? 'Refreshing...' : 'Refresh'}
        </button>
      </div>

      {error && (
        <div className="p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-sm flex items-center gap-3">
          <AlertTriangle className="w-5 h-5 shrink-0 text-rose-400" />
          <span>{error}</span>
        </div>
      )}

      {loading && !dashboardData ? (
        <div className="flex flex-col items-center justify-center py-20 text-slate-400 space-y-3">
          <Loader2 className="w-8 h-8 animate-spin text-purple-500" />
          <p className="text-sm">Aggregating company-wide metrics...</p>
        </div>
      ) : (
        <>
          {/* SIX LIVE KPI COUNTERS */}
          <div>
            <div className="flex items-center justify-between mb-3 px-1">
              <h2 className="text-xs font-semibold uppercase tracking-wider text-slate-400">Live Counts</h2>
              <span className="text-[11px] text-slate-500">Deadlines evaluated in organization timezone</span>
            </div>

            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3.5">
              {/* 1. Active */}
              <div className="p-4 rounded-xl glass-card border border-slate-800/80 hover:border-indigo-500/30 transition-all">
                <div className="flex items-center justify-between">
                  <span className="text-[11px] uppercase tracking-wider text-slate-400 font-semibold">Active</span>
                  <div className="w-7 h-7 rounded-lg bg-indigo-500/10 flex items-center justify-center">
                    <Activity className="w-3.5 h-3.5 text-indigo-400" />
                  </div>
                </div>
                <div className="text-3xl font-bold text-white mt-2" data-testid="count-active">
                  {counts.active}
                </div>
                <p className="text-[11px] text-slate-500 mt-1">In progress & assigned</p>
              </div>

              {/* 2. Completed Today */}
              <div className="p-4 rounded-xl glass-card border border-slate-800/80 hover:border-emerald-500/30 transition-all">
                <div className="flex items-center justify-between">
                  <span className="text-[11px] uppercase tracking-wider text-slate-400 font-semibold">Completed Today</span>
                  <div className="w-7 h-7 rounded-lg bg-emerald-500/10 flex items-center justify-center">
                    <CheckCircle2 className="w-3.5 h-3.5 text-emerald-400" />
                  </div>
                </div>
                <div className="text-3xl font-bold text-emerald-400 mt-2" data-testid="count-completed-today">
                  {counts.completed_today}
                </div>
                <p className="text-[11px] text-slate-500 mt-1">Finished since midnight</p>
              </div>

              {/* 3. Overdue */}
              <div className="p-4 rounded-xl glass-card border border-slate-800/80 hover:border-rose-500/30 transition-all">
                <div className="flex items-center justify-between">
                  <span className="text-[11px] uppercase tracking-wider text-slate-400 font-semibold">Overdue</span>
                  <div className="w-7 h-7 rounded-lg bg-rose-500/10 flex items-center justify-center">
                    <Clock className="w-3.5 h-3.5 text-rose-400" />
                  </div>
                </div>
                <div className="text-3xl font-bold text-rose-400 mt-2" data-testid="count-overdue">
                  {counts.overdue}
                </div>
                <p className="text-[11px] text-slate-500 mt-1">Past due date</p>
              </div>

              {/* 4. Blocked */}
              <div className="p-4 rounded-xl glass-card border border-slate-800/80 hover:border-amber-500/30 transition-all">
                <div className="flex items-center justify-between">
                  <span className="text-[11px] uppercase tracking-wider text-slate-400 font-semibold">Blocked</span>
                  <div className="w-7 h-7 rounded-lg bg-amber-500/10 flex items-center justify-center">
                    <Lock className="w-3.5 h-3.5 text-amber-400" />
                  </div>
                </div>
                <div className="text-3xl font-bold text-amber-400 mt-2" data-testid="count-blocked">
                  {counts.blocked}
                </div>
                <p className="text-[11px] text-slate-500 mt-1">Waiting on resolution</p>
              </div>

              {/* 5. At Risk */}
              <div className="p-4 rounded-xl glass-card border border-slate-800/80 hover:border-purple-500/30 transition-all">
                <div className="flex items-center justify-between">
                  <span className="text-[11px] uppercase tracking-wider text-slate-400 font-semibold">At Risk</span>
                  <div className="w-7 h-7 rounded-lg bg-purple-500/10 flex items-center justify-center">
                    <ShieldAlert className="w-3.5 h-3.5 text-purple-400" />
                  </div>
                </div>
                <div className="text-3xl font-bold text-purple-400 mt-2" data-testid="count-at-risk">
                  {counts.at_risk}
                </div>
                <p className="text-[11px] text-slate-500 mt-1">Overdue dependency</p>
              </div>

              {/* 6. Awaiting Approval */}
              <div className="p-4 rounded-xl glass-card border border-slate-800/80 hover:border-sky-500/30 transition-all">
                <div className="flex items-center justify-between">
                  <span className="text-[11px] uppercase tracking-wider text-slate-400 font-semibold">Awaiting Review</span>
                  <div className="w-7 h-7 rounded-lg bg-sky-500/10 flex items-center justify-center">
                    <FileText className="w-3.5 h-3.5 text-sky-400" />
                  </div>
                </div>
                <div className="text-3xl font-bold text-sky-400 mt-2" data-testid="count-awaiting-approval">
                  {counts.awaiting_approval}
                </div>
                <p className="text-[11px] text-slate-500 mt-1">Extracted meetings</p>
              </div>
            </div>
          </div>

          {/* THREE EXCEPTION LISTS */}
          <div className="space-y-6">
            <div className="border-t border-slate-800/60 pt-6">
              <h2 className="text-lg font-bold text-white">Exception Lists</h2>
              <p className="text-xs text-slate-400 mt-0.5">
                Executive attention queue highlighting bottlenecks, schedule cascades, and pending dispatches.
              </p>
            </div>

            {/* 1. CRITICAL BLOCKERS */}
            <div className="rounded-2xl glass-panel border border-slate-800/80 overflow-hidden">
              <div className="px-6 py-4 border-b border-slate-800 bg-slate-900/60 flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <div className="w-8 h-8 rounded-lg bg-amber-500/10 border border-amber-500/20 flex items-center justify-center">
                    <Lock className="w-4 h-4 text-amber-400" />
                  </div>
                  <div>
                    <div className="flex items-center gap-2">
                      <h3 className="font-semibold text-white text-sm">Critical Blockers</h3>
                      <span className="px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-300 border border-amber-500/20">
                        {criticalBlockers.length}
                      </span>
                    </div>
                    <p className="text-xs text-slate-400">Blocked tasks, who is waiting on whom, and duration.</p>
                  </div>
                </div>
              </div>

              <div className="p-6">
                {criticalBlockers.length === 0 ? (
                  <div className="py-8 text-center flex flex-col items-center justify-center text-slate-500">
                    <div className="w-10 h-10 rounded-full bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center mb-2">
                      <Check className="w-5 h-5 text-emerald-400" />
                    </div>
                    <p className="text-sm font-medium text-slate-300">No critical blockers</p>
                    <p className="text-xs text-slate-500 mt-0.5">All active tasks across the organization are progressing normally.</p>
                  </div>
                ) : (
                  <div className="space-y-3.5">
                    {criticalBlockers.map((task) => {
                      const priorityClass = PRIORITY_BADGES[task.priority] || PRIORITY_BADGES.medium;
                      const reasonLabel = REASON_LABELS[task.reason_code] || task.reason_code || 'Blocker';

                      return (
                        <div
                          key={task.id}
                          className="p-4 rounded-xl bg-slate-900/50 border border-slate-800 hover:border-slate-700/80 transition-all flex flex-col lg:flex-row lg:items-center justify-between gap-4"
                        >
                          <div className="space-y-1.5 min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                              <span className="font-semibold text-white text-sm">{task.title}</span>
                              <span className={`text-[10px] font-semibold uppercase px-2 py-0.5 rounded-full border ${priorityClass}`}>
                                {task.priority}
                              </span>
                              <span className="text-xs text-amber-400 font-medium px-2 py-0.5 rounded-md bg-amber-500/10 border border-amber-500/20">
                                {reasonLabel}
                              </span>
                              <span className="text-xs text-slate-400 flex items-center gap-1 bg-slate-800/80 px-2 py-0.5 rounded-md">
                                <Clock className="w-3 h-3 text-slate-400" />
                                Blocked for {task.blocked_duration}
                              </span>
                            </div>

                            {/* Who is waiting on whom */}
                            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-400 pt-1">
                              <div className="flex items-center gap-1.5">
                                <span className="text-slate-500">Task Owner:</span>
                                <span className="text-slate-200 font-medium">{task.owner?.name || 'Unassigned'}</span>
                              </div>

                              {task.blocked_by && task.blocked_by.name !== task.owner?.name && (
                                <div className="flex items-center gap-1.5">
                                  <span className="text-slate-500">Raised By:</span>
                                  <span className="text-slate-200">{task.blocked_by.name}</span>
                                </div>
                              )}

                              {task.due_date && (
                                <div className="flex items-center gap-1">
                                  <Calendar className="w-3 h-3 text-slate-500" />
                                  <span>Due: {task.due_date}</span>
                                </div>
                              )}
                            </div>

                            {/* Blocker description / dependency detail */}
                            {task.waiting_on && (
                              <div className="mt-2 text-xs p-2.5 rounded-lg bg-slate-800/40 border border-slate-800 flex items-start gap-2">
                                <AlertTriangle className="w-3.5 h-3.5 text-amber-400 shrink-0 mt-0.5" />
                                <div>
                                  <span className="text-slate-400 font-medium">Waiting On: </span>
                                  {task.waiting_on.type === 'task' ? (
                                    <span className="text-slate-200">
                                      Task #{task.waiting_on.task_id} &ldquo;{task.waiting_on.task_title}&rdquo; (Assigned to {task.waiting_on.owner_name})
                                    </span>
                                  ) : (
                                    <span className="text-slate-200">{task.waiting_on.details}</span>
                                  )}
                                </div>
                              </div>
                            )}
                          </div>
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>
            </div>

            {/* 2. AT RISK */}
            <div className="rounded-2xl glass-panel border border-slate-800/80 overflow-hidden">
              <div className="px-6 py-4 border-b border-slate-800 bg-slate-900/60 flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <div className="w-8 h-8 rounded-lg bg-purple-500/10 border border-purple-500/20 flex items-center justify-center">
                    <ShieldAlert className="w-4 h-4 text-purple-400" />
                  </div>
                  <div>
                    <div className="flex items-center gap-2">
                      <h3 className="font-semibold text-white text-sm">At Risk</h3>
                      <span className="px-2 py-0.5 rounded-full text-xs font-semibold bg-purple-500/10 text-purple-300 border border-purple-500/20">
                        {atRiskTasks.length}
                      </span>
                    </div>
                    <p className="text-xs text-slate-400">
                      Tasks not yet complete that have an active dependency on a task that is now overdue. Computed dynamically on read.
                    </p>
                  </div>
                </div>
              </div>

              <div className="p-6">
                {atRiskTasks.length === 0 ? (
                  <div className="py-8 text-center flex flex-col items-center justify-center text-slate-500">
                    <div className="w-10 h-10 rounded-full bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center mb-2">
                      <Check className="w-5 h-5 text-emerald-400" />
                    </div>
                    <p className="text-sm font-medium text-slate-300">No tasks at risk</p>
                    <p className="text-xs text-slate-500 mt-0.5">All task dependencies are satisfied or upstream tasks are on schedule.</p>
                  </div>
                ) : (
                  <div className="space-y-4">
                    {atRiskTasks.map((task) => {
                      const priorityClass = PRIORITY_BADGES[task.priority] || PRIORITY_BADGES.medium;

                      return (
                        <div
                          key={task.id}
                          className="p-4 rounded-xl bg-slate-900/50 border border-slate-800 hover:border-purple-500/30 transition-all space-y-3"
                        >
                          <div className="flex flex-col md:flex-row md:items-center justify-between gap-2">
                            <div>
                              <div className="flex items-center gap-2">
                                <span className="font-semibold text-white text-sm">{task.title}</span>
                                <span className={`text-[10px] font-semibold uppercase px-2 py-0.5 rounded-full border ${priorityClass}`}>
                                  {task.priority}
                                </span>
                                <span className="text-[10px] uppercase font-semibold px-2 py-0.5 rounded-full bg-purple-500/10 text-purple-300 border border-purple-500/30">
                                  Cascading Risk
                                </span>
                              </div>
                              <div className="flex items-center gap-3 text-xs text-slate-400 mt-1">
                                <span>Owner: <strong className="text-slate-200">{task.owner?.name || 'Unassigned'}</strong></span>
                                {task.due_date && <span>Target Due: {task.due_date}</span>}
                              </div>
                            </div>
                          </div>

                          {/* Upstream overdue dependencies */}
                          <div className="mt-2 pt-2 border-t border-slate-800 space-y-2">
                            <span className="text-[11px] font-semibold uppercase tracking-wider text-rose-400 flex items-center gap-1.5">
                              <AlertOctagon className="w-3.5 h-3.5" />
                              Overdue Upstream Dependency:
                            </span>

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
                              {task.blocking_dependencies?.map((dep) => (
                                <div
                                  key={dep.task_id}
                                  className="p-3 rounded-lg bg-rose-950/20 border border-rose-900/40 text-xs flex items-center justify-between gap-2"
                                >
                                  <div>
                                    <div className="font-medium text-rose-200">
                                      #{dep.task_id} {dep.title}
                                    </div>
                                    <div className="text-[11px] text-slate-400 mt-0.5">
                                      Assigned to {dep.owner?.name || 'Unassigned'} &bull; Due: {dep.due_date || 'No date'}
                                    </div>
                                  </div>

                                  <div className="text-right shrink-0">
                                    <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-500/20 text-rose-300 border border-rose-500/30">
                                      {dep.days_overdue > 0 ? `${dep.days_overdue}d overdue` : 'Overdue'}
                                    </span>
                                  </div>
                                </div>
                              ))}
                            </div>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>
            </div>

            {/* 3. AWAITING APPROVAL */}
            <div className="rounded-2xl glass-panel border border-slate-800/80 overflow-hidden">
              <div className="px-6 py-4 border-b border-slate-800 bg-slate-900/60 flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <div className="w-8 h-8 rounded-lg bg-sky-500/10 border border-sky-500/20 flex items-center justify-center">
                    <FileText className="w-4 h-4 text-sky-400" />
                  </div>
                  <div>
                    <div className="flex items-center gap-2">
                      <h3 className="font-semibold text-white text-sm">Awaiting Approval</h3>
                      <span className="px-2 py-0.5 rounded-full text-xs font-semibold bg-sky-500/10 text-sky-300 border border-sky-500/20">
                        {awaitingApproval.length}
                      </span>
                    </div>
                    <p className="text-xs text-slate-400">Meetings extracted by AI but not yet reviewed or approved.</p>
                  </div>
                </div>
              </div>

              <div className="p-6">
                {awaitingApproval.length === 0 ? (
                  <div className="py-8 text-center flex flex-col items-center justify-center text-slate-500">
                    <div className="w-10 h-10 rounded-full bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center mb-2">
                      <Check className="w-5 h-5 text-emerald-400" />
                    </div>
                    <p className="text-sm font-medium text-slate-300">No meetings awaiting review</p>
                    <p className="text-xs text-slate-500 mt-0.5">All meeting transcripts and extracted action items have been approved.</p>
                  </div>
                ) : (
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {awaitingApproval.map((meeting) => (
                      <div
                        key={meeting.id}
                        className="p-4 rounded-xl bg-slate-900/50 border border-slate-800 hover:border-sky-500/30 transition-all flex flex-col justify-between gap-4"
                      >
                        <div className="space-y-2">
                          <div className="flex items-center justify-between">
                            <span className="text-[10px] font-semibold uppercase px-2 py-0.5 rounded-full bg-sky-500/10 text-sky-400 border border-sky-500/20">
                              Source: {meeting.source || 'Transcript'}
                            </span>
                            {meeting.meeting_date && (
                              <span className="text-xs text-slate-400 flex items-center gap-1">
                                <Calendar className="w-3 h-3 text-slate-500" />
                                {meeting.meeting_date}
                              </span>
                            )}
                          </div>

                          <h4 className="font-semibold text-white text-base">{meeting.title}</h4>

                          <div className="flex items-center gap-2 text-xs text-slate-400">
                            <User className="w-3 h-3 text-slate-500" />
                            <span>Uploaded by: <strong className="text-slate-300">{meeting.creator?.name || 'Admin'}</strong></span>
                          </div>

                          <div className="p-2.5 rounded-lg bg-slate-800/40 border border-slate-800 text-xs flex items-center justify-between">
                            <span className="text-slate-400">Pending extracted tasks:</span>
                            <span className="font-semibold text-amber-400">{meeting.pending_tasks_count} tasks</span>
                          </div>
                        </div>

                        <div className="pt-2 border-t border-slate-800/60">
                          <button
                            onClick={() => navigate(meeting.review_url || `/meetings/${meeting.id}/review`)}
                            className="w-full inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold bg-sky-500 hover:bg-sky-600 text-white shadow-lg shadow-sky-500/10 transition-colors"
                          >
                            <span>Open Review Screen</span>
                            <ArrowRight className="w-3.5 h-3.5" />
                          </button>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </div>
          </div>
        </>
      )}
    </div>
  );
};
