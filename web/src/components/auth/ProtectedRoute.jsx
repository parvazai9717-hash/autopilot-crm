import React from 'react';
import { Navigate, useLocation, Outlet } from 'react-router-dom';
import { useAuth, getDefaultRouteForRole } from '../../context/AuthContext';
import { Loader2 } from 'lucide-react';

export const ProtectedRoute = ({ allowedRoles, children }) => {
  const { user, loading, isAuthenticated } = useAuth();
  const location = useLocation();

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-slate-950 text-indigo-400">
        <div className="flex flex-col items-center gap-3">
          <Loader2 className="w-8 h-8 animate-spin text-indigo-500" />
          <span className="text-sm font-medium text-slate-400">Loading Autopilot session...</span>
        </div>
      </div>
    );
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" state={{ from: location }} replace />;
  }

  if (allowedRoles && !allowedRoles.includes(user.role)) {
    // If not authorized for this specific role route, redirect to user's home dashboard
    return <Navigate to={getDefaultRouteForRole(user.role)} replace />;
  }

  return children ? children : <Outlet />;
};

export const GuestRoute = ({ children }) => {
  const { user, loading, isAuthenticated } = useAuth();

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-slate-950 text-indigo-400">
        <Loader2 className="w-8 h-8 animate-spin text-indigo-500" />
      </div>
    );
  }

  if (isAuthenticated) {
    return <Navigate to={getDefaultRouteForRole(user.role)} replace />;
  }

  return children ? children : <Outlet />;
};
