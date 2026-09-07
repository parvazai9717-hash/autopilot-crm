import React from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './context/AuthContext';
import { ProtectedRoute, GuestRoute } from './components/auth/ProtectedRoute';
import { AppShell } from './components/layout/AppShell';
import { LoginPage } from './pages/LoginPage';
import { EmployeeDashboard } from './pages/EmployeeDashboard';
import { ManagerDashboard } from './pages/ManagerDashboard';
import { ExecutiveDashboard } from './pages/ExecutiveDashboard';
import { AdminDashboard } from './pages/AdminDashboard';
import { NewMeetingPage } from './pages/NewMeetingPage';
import { MeetingReviewPage } from './pages/MeetingReviewPage';
import { UnauthorizedPage } from './pages/UnauthorizedPage';

const RootRedirect = () => {
  const { user, getDefaultRoute } = useAuth();
  if (!user) {
    return <Navigate to="/login" replace />;
  }
  return <Navigate to={getDefaultRoute()} replace />;
};

export default function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          {/* Guest Routes */}
          <Route element={<GuestRoute />}>
            <Route path="/login" element={<LoginPage />} />
          </Route>

          {/* Protected Routes inside App Shell */}
          <Route element={<ProtectedRoute />}>
            <Route element={<AppShell />}>
              <Route path="/" element={<RootRedirect />} />

              {/* Employee Area */}
              <Route
                path="/my-tasks"
                element={<EmployeeDashboard />}
              />

              {/* Manager Area */}
              <Route
                path="/manager"
                element={
                  <ProtectedRoute allowedRoles={['manager', 'admin', 'executive']}>
                    <ManagerDashboard />
                  </ProtectedRoute>
                }
              />

              {/* Executive Area */}
              <Route
                path="/executive"
                element={
                  <ProtectedRoute allowedRoles={['executive', 'admin']}>
                    <ExecutiveDashboard />
                  </ProtectedRoute>
                }
              />

              {/* Admin Area */}
              <Route
                path="/admin"
                element={
                  <ProtectedRoute allowedRoles={['admin']}>
                    <AdminDashboard />
                  </ProtectedRoute>
                }
              />

              {/* Admin New Meeting Ingestion */}
              <Route
                path="/meetings/new"
                element={
                  <ProtectedRoute allowedRoles={['admin']}>
                    <NewMeetingPage />
                  </ProtectedRoute>
                }
              />

              {/* Meeting Review Screen (Approvers: Admin or Meeting Creator) */}
              <Route
                path="/meetings/:id/review"
                element={<MeetingReviewPage />}
              />

              <Route path="/unauthorized" element={<UnauthorizedPage />} />
            </Route>
          </Route>

          {/* Catch-all */}
          <Route path="*" element={<RootRedirect />} />
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  );
}
