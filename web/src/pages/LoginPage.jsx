import React, { useState } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useAuth, getDefaultRouteForRole } from '../context/AuthContext';
import { Sparkles, Lock, Mail, AlertCircle, Loader2, ArrowRight, UserCheck } from 'lucide-react';

const DEMO_USERS = [
  {
    role: 'Employee',
    name: 'Ahmed Raza',
    email: 'ahmed@test.com',
    desc: 'Assigned tasks, blockers flow',
    badge: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30',
  },
  {
    role: 'Manager',
    name: 'Bilal Sheikh',
    email: 'bilal@test.com',
    desc: 'Direct reports, blockers resolution',
    badge: 'bg-sky-500/20 text-sky-300 border-sky-500/30',
  },
  {
    role: 'Admin',
    name: 'Ahmad Ameen',
    email: 'admin@test.com',
    desc: 'Review screen, config, webhooks',
    badge: 'bg-indigo-500/20 text-indigo-300 border-indigo-500/30',
  },
  {
    role: 'Executive',
    name: 'Imran Malik',
    email: 'exec@test.com',
    desc: 'Company KPIs, exception lists',
    badge: 'bg-purple-500/20 text-purple-300 border-purple-500/30',
  },
];

export const LoginPage = () => {
  const { login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();

  const [email, setEmail] = useState('admin@test.com');
  const [password, setPassword] = useState('password123');
  const [remember, setRemember] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [errorMsg, setErrorMsg] = useState('');

  const handleSubmit = async (e) => {
    e?.preventDefault();
    if (!email || !password) {
      setErrorMsg('Please enter both email and password.');
      return;
    }

    setErrorMsg('');
    setSubmitting(true);

    try {
      const user = await login(email, password, remember);
      const destination = location.state?.from?.pathname || getDefaultRouteForRole(user.role);
      navigate(destination, { replace: true });
    } catch (err) {
      console.error('Login error occurred:', err);
      const message = err.apiError?.message || err.message || 'Login failed. Please check your credentials.';
      setErrorMsg(message);
    } finally {
      setSubmitting(false);
    }
  };

  const handleQuickFill = (demoEmail) => {
    setEmail(demoEmail);
    setPassword('password123');
    setErrorMsg('');
  };

  return (
    <div className="min-h-screen bg-slate-950 flex flex-col justify-center py-12 sm:px-6 lg:px-8 relative overflow-hidden">
      {/* Ambient background glows */}
      <div className="absolute top-1/4 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[400px] bg-indigo-600/15 blur-[120px] rounded-full pointer-events-none" />
      <div className="absolute bottom-1/4 right-1/4 w-[400px] h-[300px] bg-violet-600/10 blur-[100px] rounded-full pointer-events-none" />

      <div className="sm:mx-auto sm:w-full sm:max-w-md relative z-10">
        {/* Brand Header */}
        <div className="flex flex-col items-center text-center">
          <div className="w-12 h-12 rounded-2xl bg-gradient-to-tr from-indigo-600 via-indigo-500 to-violet-500 flex items-center justify-center shadow-xl shadow-indigo-500/25 ring-1 ring-white/20 mb-4">
            <Sparkles className="w-6 h-6 text-white" />
          </div>
          <h2 className="text-2xl font-extrabold text-white tracking-tight sm:text-3xl">
            Autopilot CRM
          </h2>
          <p className="mt-1.5 text-sm text-slate-400">
            Meeting Commitments → Verified Tracked Actions
          </p>
        </div>
      </div>

      <div className="mt-8 sm:mx-auto sm:w-full sm:max-w-md relative z-10 px-4 sm:px-0">
        <div className="glass-panel py-8 px-6 shadow-2xl rounded-2xl sm:px-10 border border-slate-800/80">
          <form className="space-y-5" onSubmit={handleSubmit}>
            {errorMsg && (
              <div className="p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/30 flex items-start gap-3">
                <AlertCircle className="w-5 h-5 text-rose-400 shrink-0 mt-0.5" />
                <div className="text-xs text-rose-200 leading-relaxed font-medium">
                  {errorMsg}
                </div>
              </div>
            )}

            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-1.5">
                Work Email
              </label>
              <div className="relative rounded-xl shadow-sm">
                <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-500">
                  <Mail className="w-4 h-4" />
                </div>
                <input
                  type="email"
                  required
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder="name@company.com"
                  className="block w-full pl-10 pr-3.5 py-2.5 bg-slate-900/80 border border-slate-700/70 rounded-xl text-slate-100 placeholder-slate-500 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all"
                />
              </div>
            </div>

            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-1.5">
                Password
              </label>
              <div className="relative rounded-xl shadow-sm">
                <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-500">
                  <Lock className="w-4 h-4" />
                </div>
                <input
                  type="password"
                  required
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="••••••••••••"
                  className="block w-full pl-10 pr-3.5 py-2.5 bg-slate-900/80 border border-slate-700/70 rounded-xl text-slate-100 placeholder-slate-500 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all"
                />
              </div>
            </div>

            <div className="flex items-center justify-between">
              <label className="flex items-center gap-2 cursor-pointer text-xs text-slate-400 hover:text-slate-300">
                <input
                  type="checkbox"
                  checked={remember}
                  onChange={(e) => setRemember(e.target.checked)}
                  className="w-4 h-4 rounded bg-slate-900 border-slate-700 text-indigo-600 focus:ring-indigo-500 focus:ring-offset-slate-950"
                />
                <span>Remember this device</span>
              </label>
              <span className="text-xs text-indigo-400/80">Demo pwd: password123</span>
            </div>

            <button
              type="submit"
              disabled={submitting}
              className="w-full flex items-center justify-center gap-2 py-2.5 px-4 rounded-xl shadow-lg shadow-indigo-600/30 text-sm font-semibold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 focus:ring-offset-slate-950 disabled:opacity-50 disabled:cursor-not-allowed transition-all"
            >
              {submitting ? (
                <>
                  <Loader2 className="w-4 h-4 animate-spin" />
                  <span>Authenticating...</span>
                </>
              ) : (
                <>
                  <span>Sign In</span>
                  <ArrowRight className="w-4 h-4" />
                </>
              )}
            </button>
          </form>

          {/* Quick Demo Credentials */}
          <div className="mt-6 pt-6 border-t border-slate-800/80">
            <div className="flex items-center gap-2 text-xs font-semibold text-slate-400 mb-3">
              <UserCheck className="w-4 h-4 text-indigo-400" />
              <span>1-Click Demo Login</span>
            </div>
            <div className="grid grid-cols-2 gap-2">
              {DEMO_USERS.map((demo) => (
                <button
                  key={demo.email}
                  type="button"
                  onClick={() => handleQuickFill(demo.email)}
                  className={`text-left p-2.5 rounded-xl glass-card-hover border border-slate-800 transition-all ${
                    email === demo.email ? 'ring-1 ring-indigo-500 bg-indigo-950/30' : ''
                  }`}
                >
                  <div className="flex items-center justify-between mb-1">
                    <span className="text-xs font-semibold text-slate-200">{demo.name}</span>
                    <span className={`text-[10px] px-1.5 py-0.2 rounded font-medium border ${demo.badge}`}>
                      {demo.role}
                    </span>
                  </div>
                  <div className="text-[11px] text-slate-400 truncate">{demo.email}</div>
                </button>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};
