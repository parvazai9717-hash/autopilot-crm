import React, { useState } from 'react';
import { Outlet, NavLink, useNavigate, useLocation } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import {
  CheckSquare,
  Users,
  BarChart3,
  Settings,
  FileCheck2,
  LogOut,
  Menu,
  X,
  Sparkles,
  Layers,
  ChevronRight,
  Shield,
  PlusCircle,
} from 'lucide-react';

export const AppShell = () => {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  const handleLogout = async () => {
    await logout();
    navigate('/login');
  };

  const getRoleBadge = (role) => {
    switch (role) {
      case 'admin':
        return <span className="px-2 py-0.5 text-xs font-semibold rounded-full bg-indigo-500/20 text-indigo-300 border border-indigo-500/30">Admin</span>;
      case 'executive':
        return <span className="px-2 py-0.5 text-xs font-semibold rounded-full bg-purple-500/20 text-purple-300 border border-purple-500/30">Executive</span>;
      case 'manager':
        return <span className="px-2 py-0.5 text-xs font-semibold rounded-full bg-sky-500/20 text-sky-300 border border-sky-500/30">Manager</span>;
      case 'employee':
      default:
        return <span className="px-2 py-0.5 text-xs font-semibold rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">Employee</span>;
    }
  };

  // Define navigation items based on role
  const navItems = [
    {
      to: '/my-tasks',
      label: 'My Tasks',
      icon: CheckSquare,
      roles: ['employee', 'manager', 'admin', 'executive'],
    },
    {
      to: '/manager',
      label: 'Manager Team',
      icon: Users,
      roles: ['manager', 'admin', 'executive'],
    },
    {
      to: '/executive',
      label: 'Executive KPI',
      icon: BarChart3,
      roles: ['executive', 'admin'],
    },
    {
      to: '/meetings/new',
      label: 'New Meeting',
      icon: PlusCircle,
      roles: ['admin'],
    },
    {
      to: '/admin',
      label: 'Admin Center',
      icon: Settings,
      roles: ['admin'],
    },
  ].filter((item) => !item.roles || item.roles.includes(user?.role));

  return (
    <div className="min-h-screen bg-slate-950 flex">
      {/* Desktop Sidebar */}
      <aside className="hidden md:flex md:w-64 flex-col fixed inset-y-0 z-50 glass-panel border-r border-slate-800/80">
        {/* Brand Header */}
        <div className="h-16 flex items-center gap-3 px-6 border-b border-slate-800/60 bg-slate-900/40">
          <div className="w-9 h-9 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center shadow-lg shadow-indigo-500/30 ring-1 ring-white/20">
            <Sparkles className="w-5 h-5 text-white" />
          </div>
          <div className="flex flex-col">
            <span className="font-bold text-sm text-white tracking-wide">Autopilot CRM</span>
            <span className="text-[11px] text-slate-400 font-medium">Meeting → Action</span>
          </div>
        </div>

        {/* Organization Info */}
        <div className="px-4 py-3 border-b border-slate-800/40 bg-slate-950/40">
          <div className="flex items-center gap-2 text-xs text-slate-400">
            <Layers className="w-3.5 h-3.5 text-indigo-400 shrink-0" />
            <span className="font-medium truncate">{user?.organization?.name || 'Demo Company'}</span>
            <span className="ml-auto text-[10px] text-slate-500">{user?.organization?.timezone || 'PKT'}</span>
          </div>
        </div>

        {/* Navigation Items */}
        <nav className="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
          {navItems.map((item) => {
            const Icon = item.icon;
            const isActive = location.pathname.startsWith(item.to);
            return (
              <NavLink
                key={item.to}
                to={item.to}
                className={`flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all ${
                  isActive
                    ? 'bg-indigo-600/20 text-indigo-300 border border-indigo-500/30 shadow-sm'
                    : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900/60'
                }`}
              >
                <Icon className={`w-4 h-4 ${isActive ? 'text-indigo-400' : 'text-slate-400'}`} />
                <span>{item.label}</span>
                {isActive && <ChevronRight className="w-4 h-4 ml-auto text-indigo-400" />}
              </NavLink>
            );
          })}
        </nav>

        {/* User Profile & Logout */}
        <div className="p-3 border-t border-slate-800/80 bg-slate-900/40">
          <div className="flex items-center gap-3 p-2 rounded-xl glass-card">
            <div className="w-9 h-9 rounded-lg bg-indigo-950 border border-indigo-500/40 flex items-center justify-center font-bold text-xs text-indigo-300 shrink-0">
              {user?.name
                ?.split(' ')
                .map((n) => n[0])
                .join('')
                .toUpperCase() || 'U'}
            </div>
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-1.5">
                <span className="text-xs font-semibold text-slate-200 truncate">{user?.name}</span>
              </div>
              <div className="mt-0.5">{getRoleBadge(user?.role)}</div>
            </div>
            <button
              onClick={handleLogout}
              title="Log out"
              className="p-1.5 rounded-lg text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 transition-colors"
            >
              <LogOut className="w-4 h-4" />
            </button>
          </div>
        </div>
      </aside>

      {/* Mobile Top Navigation */}
      <div className="md:hidden fixed top-0 inset-x-0 z-50 h-16 glass-panel border-b border-slate-800 flex items-center justify-between px-4">
        <div className="flex items-center gap-2.5">
          <div className="w-8 h-8 rounded-lg bg-indigo-600 flex items-center justify-center text-white">
            <Sparkles className="w-4 h-4" />
          </div>
          <span className="font-bold text-sm text-white">Autopilot CRM</span>
        </div>
        <button
          onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
          className="p-2 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800"
        >
          {mobileMenuOpen ? <X className="w-5 h-5" /> : <Menu className="w-5 h-5" />}
        </button>
      </div>

      {/* Mobile Drawer */}
      {mobileMenuOpen && (
        <div className="md:hidden fixed inset-0 z-40 bg-slate-950/95 pt-16 flex flex-col p-4 space-y-3">
          <div className="p-3 rounded-lg glass-card flex items-center justify-between">
            <div>
              <div className="font-semibold text-sm text-slate-200">{user?.name}</div>
              <div className="text-xs text-slate-400">{user?.email}</div>
            </div>
            {getRoleBadge(user?.role)}
          </div>
          <nav className="space-y-1">
            {navItems.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                onClick={() => setMobileMenuOpen(false)}
                className="flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium text-slate-300 hover:bg-slate-800"
              >
                <item.icon className="w-5 h-5 text-indigo-400" />
                <span>{item.label}</span>
              </NavLink>
            ))}
          </nav>
          <div className="pt-4 mt-auto">
            <button
              onClick={handleLogout}
              className="w-full flex items-center justify-center gap-2 px-4 py-3 rounded-lg bg-rose-500/10 text-rose-300 border border-rose-500/30 text-sm font-medium"
            >
              <LogOut className="w-4 h-4" />
              <span>Log out</span>
            </button>
          </div>
        </div>
      )}

      {/* Main Content Area */}
      <main className="flex-1 md:pl-64 pt-16 md:pt-0 min-h-screen flex flex-col bg-slate-950">
        <div className="flex-1 p-6 md:p-8 max-w-7xl w-full mx-auto">
          <Outlet />
        </div>
      </main>
    </div>
  );
};
