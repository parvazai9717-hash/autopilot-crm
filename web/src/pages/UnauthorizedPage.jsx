import React from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth, getDefaultRouteForRole } from '../context/AuthContext';
import { ShieldAlert, ArrowLeft } from 'lucide-react';

export const UnauthorizedPage = () => {
  const { user } = useAuth();
  const navigate = useNavigate();

  const handleReturn = () => {
    navigate(getDefaultRouteForRole(user?.role), { replace: true });
  };

  return (
    <div className="min-h-[60vh] flex flex-col items-center justify-center text-center p-6">
      <div className="w-16 h-16 rounded-2xl bg-rose-500/20 text-rose-400 flex items-center justify-center mb-4">
        <ShieldAlert className="w-8 h-8" />
      </div>
      <h1 className="text-xl font-bold text-white">Access Restricted</h1>
      <p className="text-sm text-slate-400 max-w-md mt-2">
        Your account role (<span className="text-indigo-300 font-semibold">{user?.role}</span>) does not have permission to access this area.
      </p>
      <button
        onClick={handleReturn}
        className="mt-6 inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm font-medium text-slate-200 transition-colors"
      >
        <ArrowLeft className="w-4 h-4" />
        <span>Return to Your Dashboard</span>
      </button>
    </div>
  );
};
