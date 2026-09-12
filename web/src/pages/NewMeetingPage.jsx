import React, { useState, useEffect, useRef } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { meetingApi } from '../services/meetingApi';
import {
  FileText,
  UploadCloud,
  Clock,
  CheckCircle2,
  AlertCircle,
  RefreshCw,
  ArrowRight,
  Sparkles,
  FileAudio,
  Trash2,
  Calendar,
  Layers,
  ChevronRight,
  Loader2,
  ShieldCheck,
  PlusCircle,
} from 'lucide-react';

export const NewMeetingPage = () => {
  const { user } = useAuth();
  const navigate = useNavigate();

  // Active tab: 'transcript' or 'upload'
  const [activeTab, setActiveTab] = useState('transcript');

  // Shared form state
  const [title, setTitle] = useState('');
  const [meetingDate, setMeetingDate] = useState(() => new Date().toISOString().split('T')[0]);

  // Transcript tab state
  const [transcript, setTranscript] = useState('');
  const [summary, setSummary] = useState('');

  // Audio upload tab state
  const [audioFile, setAudioFile] = useState(null);
  const [dragOver, setDragOver] = useState(false);
  const fileInputRef = useRef(null);

  // Submission & processing state
  const [submitting, setSubmitting] = useState(false);
  const [uploadProgress, setUploadProgress] = useState(0);
  const [error, setError] = useState(null);

  // Active meeting tracker state
  const [activeMeeting, setActiveMeeting] = useState(null);
  const [isRetrying, setIsRetrying] = useState(false);

  // Polling ref for cleanup
  const pollIntervalRef = useRef(null);

  // Clean up polling timer on unmount
  useEffect(() => {
    return () => {
      if (pollIntervalRef.current) {
        clearInterval(pollIntervalRef.current);
      }
    };
  }, []);

  // Status poller while meeting is uploaded or processing
  useEffect(() => {
    if (!activeMeeting?.id) return;

    const currentStatus = activeMeeting.status;
    const shouldPoll = currentStatus === 'uploaded' || currentStatus === 'processing';

    if (!shouldPoll) {
      if (pollIntervalRef.current) {
        clearInterval(pollIntervalRef.current);
        pollIntervalRef.current = null;
      }
      return;
    }

    const pollStatus = async () => {
      try {
        const data = await meetingApi.getStatus(activeMeeting.id);
        setActiveMeeting((prev) => ({
          ...prev,
          status: data.status,
          task_count: data.task_count,
          error_message: data.error_message,
          audio_duration_seconds: data.audio_duration_seconds,
        }));
      } catch (err) {
        console.error('Polling status error:', err);
      }
    };

    // Poll every 2.5 seconds
    pollIntervalRef.current = setInterval(pollStatus, 2500);

    return () => {
      if (pollIntervalRef.current) {
        clearInterval(pollIntervalRef.current);
        pollIntervalRef.current = null;
      }
    };
  }, [activeMeeting?.id, activeMeeting?.status]);

  // Handle Audio File Drag & Drop
  const handleDragOver = (e) => {
    e.preventDefault();
    setDragOver(true);
  };

  const handleDragLeave = (e) => {
    e.preventDefault();
    setDragOver(false);
  };

  const handleDrop = (e) => {
    e.preventDefault();
    setDragOver(false);
    if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
      validateAndSetFile(e.dataTransfer.files[0]);
    }
  };

  const handleFileSelect = (e) => {
    if (e.target.files && e.target.files.length > 0) {
      validateAndSetFile(e.target.files[0]);
    }
  };

  const validateAndSetFile = (file) => {
    setError(null);
    const validExtensions = ['mp3', 'm4a', 'wav', 'mp4', 'webm', 'ogg'];
    const ext = file.name.split('.').pop().toLowerCase();

    if (!validExtensions.includes(ext)) {
      setError(`Invalid file format .${ext}. Supported formats: ${validExtensions.join(', ').toUpperCase()}`);
      return;
    }

    const maxBytes = 500 * 1024 * 1024; // 500 MB
    if (file.size > maxBytes) {
      setError('File size exceeds the 500MB maximum limit.');
      return;
    }

    setAudioFile(file);
    // Auto-fill title if empty
    if (!title) {
      const suggestedTitle = file.name.replace(/\.[^/.]+$/, '').replace(/[-_]/g, ' ');
      setTitle(suggestedTitle.charAt(0).toUpperCase() + suggestedTitle.slice(1));
    }
  };

  // Submit Transcript Meeting
  const handleSubmitTranscript = async (e) => {
    e.preventDefault();
    setError(null);

    if (!title.trim()) {
      setError('Please provide a meeting title.');
      return;
    }

    if (!transcript.trim()) {
      setError('Please paste or write the meeting transcript.');
      return;
    }

    setSubmitting(true);
    try {
      const data = await meetingApi.createTranscriptMeeting({
        title: title.trim(),
        meeting_date: meetingDate,
        transcript: transcript.trim(),
        summary: summary.trim() || null,
      });

      setActiveMeeting({
        id: data.meeting_id,
        title: data.title,
        status: data.status,
        meeting_date: data.meeting_date,
        source: 'text',
        task_count: 0,
        error_message: null,
      });
    } catch (err) {
      setError(err.apiError?.message || 'Failed to submit transcript. Please check your inputs and try again.');
    } finally {
      setSubmitting(false);
    }
  };

  // Submit Audio Upload Meeting
  const handleSubmitAudio = async (e) => {
    e.preventDefault();
    setError(null);

    if (!title.trim()) {
      setError('Please provide a meeting title.');
      return;
    }

    if (!audioFile) {
      setError('Please select an audio or video recording file.');
      return;
    }

    setSubmitting(true);
    setUploadProgress(0);

    try {
      const formData = new FormData();
      formData.append('file', audioFile);
      formData.append('title', title.trim());
      formData.append('meeting_date', meetingDate);

      const data = await meetingApi.uploadAudio(formData, (percent) => {
        setUploadProgress(percent);
      });

      setActiveMeeting({
        id: data.meeting_id,
        title: data.title,
        status: data.status,
        meeting_date: data.meeting_date,
        source: 'upload',
        task_count: 0,
        error_message: null,
      });
    } catch (err) {
      setError(err.apiError?.message || 'Audio upload failed. Please verify the file size and network connection.');
    } finally {
      setSubmitting(false);
      setUploadProgress(0);
    }
  };

  // Retry Failed Audio Processing
  const handleRetry = async () => {
    if (!activeMeeting?.id) return;
    setIsRetrying(true);
    setError(null);

    try {
      const data = await meetingApi.retryMeeting(activeMeeting.id);
      setActiveMeeting((prev) => ({
        ...prev,
        status: data.status,
        error_message: null,
      }));
    } catch (err) {
      setError(err.apiError?.message || 'Failed to retry meeting processing.');
    } finally {
      setIsRetrying(false);
    }
  };

  // Reset form to ingest another meeting
  const handleIngestAnother = () => {
    setActiveMeeting(null);
    setTitle('');
    setTranscript('');
    setSummary('');
    setAudioFile(null);
    setError(null);
    setUploadProgress(0);
  };

  // Render Status Badge
  const renderStatusBadge = (status) => {
    switch (status) {
      case 'uploaded':
        return (
          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-indigo-500/20 text-indigo-300 border border-indigo-500/30 animate-pulse">
            <Clock className="w-3.5 h-3.5" />
            Uploaded — Queued for Audio Conversion
          </span>
        );
      case 'processing':
        return (
          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-amber-500/20 text-amber-300 border border-amber-500/30 animate-pulse">
            <Loader2 className="w-3.5 h-3.5 animate-spin" />
            Processing — AI Extraction Active
          </span>
        );
      case 'extracted':
        return (
          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 shadow-sm shadow-emerald-500/20">
            <CheckCircle2 className="w-3.5 h-3.5 text-emerald-400" />
            Extraction Complete — Ready for Review
          </span>
        );
      case 'reviewed':
        return (
          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-blue-500/20 text-blue-300 border border-blue-500/30">
            <CheckCircle2 className="w-3.5 h-3.5" />
            Reviewed & Dispatched
          </span>
        );
      case 'failed':
        return (
          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-rose-500/20 text-rose-300 border border-rose-500/40">
            <AlertCircle className="w-3.5 h-3.5 text-rose-400" />
            Processing Failed
          </span>
        );
      default:
        return (
          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-slate-500/20 text-slate-300 border border-slate-500/30">
            {status}
          </span>
        );
    }
  };

  return (
    <div className="space-y-6 pb-16">
      {/* Top Header */}
      <div className="max-w-4xl mx-auto mb-8">
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <div className="flex items-center gap-2 text-xs text-indigo-400 font-semibold uppercase tracking-wider mb-1">
              <Sparkles className="w-3.5 h-3.5" />
              <span>Admin Ingestion Suite</span>
            </div>
            <h1 className="text-2xl sm:text-3xl font-bold text-white tracking-tight">New Meeting</h1>
            <p className="text-sm text-slate-400 mt-1">
              Upload recordings or paste transcripts to initiate AI-powered action item detection.
            </p>
          </div>

          <div className="flex items-center gap-2">
            <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-medium bg-slate-800/80 text-slate-300 border border-slate-700/60">
              <ShieldCheck className="w-3.5 h-3.5 text-indigo-400" />
              Admin Policy Authorized
            </span>
          </div>
        </div>
      </div>

      <div className="max-w-4xl mx-auto space-y-6">
        {/* Error Alert */}
        {error && (
          <div className="p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-sm flex items-start gap-3">
            <AlertCircle className="w-5 h-5 text-rose-400 shrink-0 mt-0.5" />
            <div className="flex-1">
              <p className="font-semibold text-rose-200">Ingestion Warning</p>
              <p className="mt-0.5">{error}</p>
            </div>
          </div>
        )}

        {/* If a meeting was submitted, show the live processing card */}
        {activeMeeting ? (
          <div className="glass-panel p-6 sm:p-8 rounded-2xl border border-indigo-500/30 shadow-xl shadow-indigo-950/40 relative overflow-hidden">
            {/* Ambient Background Glow */}
            <div className="absolute top-0 right-0 w-96 h-96 bg-indigo-500/10 rounded-full blur-3xl pointer-events-none -mr-20 -mt-20" />

            <div className="relative z-10 space-y-6">
              <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-4 pb-6 border-b border-slate-800">
                <div>
                  <span className="text-xs font-mono text-indigo-400">MEETING #{activeMeeting.id}</span>
                  <h2 className="text-xl sm:text-2xl font-bold text-white mt-1">{activeMeeting.title}</h2>
                  <div className="flex flex-wrap items-center gap-4 text-xs text-slate-400 mt-2">
                    <span className="flex items-center gap-1.5">
                      <Calendar className="w-3.5 h-3.5 text-slate-400" />
                      {activeMeeting.meeting_date || 'Today'}
                    </span>
                    <span className="flex items-center gap-1.5">
                      {activeMeeting.source === 'upload' ? (
                        <>
                          <FileAudio className="w-3.5 h-3.5 text-indigo-400" />
                          Audio/Video Recording
                        </>
                      ) : (
                        <>
                          <FileText className="w-3.5 h-3.5 text-indigo-400" />
                          Pasted Transcript
                        </>
                      )}
                    </span>
                    {activeMeeting.task_count > 0 && (
                      <span className="flex items-center gap-1.5 text-emerald-400 font-semibold">
                        <CheckCircle2 className="w-3.5 h-3.5" />
                        {activeMeeting.task_count} action items detected
                      </span>
                    )}
                  </div>
                </div>

                <div className="flex flex-col items-start sm:items-end gap-2">
                  {renderStatusBadge(activeMeeting.status)}
                </div>
              </div>

              {/* Status Details / Progress Body */}
              <div className="space-y-4">
                {activeMeeting.status === 'uploaded' && (
                  <div className="p-4 rounded-xl bg-slate-900/60 border border-slate-800 space-y-3">
                    <div className="flex items-center gap-3">
                      <div className="w-8 h-8 rounded-lg bg-indigo-600/20 border border-indigo-500/30 flex items-center justify-center text-indigo-400">
                        <Loader2 className="w-4 h-4 animate-spin" />
                      </div>
                      <div>
                        <p className="text-sm font-semibold text-slate-200">FFmpeg Conversion in Progress</p>
                        <p className="text-xs text-slate-400">
                          Converting audio to 32kbps mono MP3 and computing media duration...
                        </p>
                      </div>
                    </div>
                  </div>
                )}

                {activeMeeting.status === 'processing' && (
                  <div className="p-4 rounded-xl bg-slate-900/60 border border-slate-800 space-y-3">
                    <div className="flex items-center gap-3">
                      <div className="w-8 h-8 rounded-lg bg-amber-500/20 border border-amber-500/30 flex items-center justify-center text-amber-400">
                        <Loader2 className="w-4 h-4 animate-spin" />
                      </div>
                      <div>
                        <p className="text-sm font-semibold text-slate-200">AI Extraction Pipeline Active</p>
                        <p className="text-xs text-slate-400">
                          Waiting for n8n to analyze transcript, identify owners, and extract action items...
                        </p>
                      </div>
                    </div>
                  </div>
                )}

                {activeMeeting.status === 'extracted' && (
                  <div className="p-5 rounded-xl bg-emerald-950/30 border border-emerald-500/40 space-y-4">
                    <div className="flex items-start gap-3">
                      <div className="w-9 h-9 rounded-xl bg-emerald-500/20 border border-emerald-500/30 flex items-center justify-center text-emerald-400 shrink-0">
                        <CheckCircle2 className="w-5 h-5" />
                      </div>
                      <div className="flex-1">
                        <h3 className="text-sm font-bold text-emerald-200">AI Extraction Finished!</h3>
                        <p className="text-xs text-emerald-300/80 mt-0.5">
                          {activeMeeting.task_count > 0
                            ? `${activeMeeting.task_count} action items were successfully extracted and are awaiting review.`
                            : 'Extraction finished. Proceed to review tasks and ambiguous ownership.'}
                        </p>
                      </div>
                    </div>

                    {/* Prominent Action Button Linking to Review Screen */}
                    <div className="pt-2">
                      <Link
                        to={`/meetings/${activeMeeting.id}/review`}
                        className="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white font-bold text-sm shadow-lg shadow-emerald-600/30 hover:shadow-emerald-500/40 transition-all transform hover:-translate-y-0.5"
                      >
                        <span>Go to Review Screen</span>
                        <ArrowRight className="w-4 h-4" />
                      </Link>
                    </div>
                  </div>
                )}

                {activeMeeting.status === 'failed' && (
                  <div className="p-5 rounded-xl bg-rose-950/30 border border-rose-500/40 space-y-4">
                    <div className="flex items-start gap-3">
                      <div className="w-9 h-9 rounded-xl bg-rose-500/20 border border-rose-500/30 flex items-center justify-center text-rose-400 shrink-0">
                        <AlertCircle className="w-5 h-5" />
                      </div>
                      <div className="flex-1">
                        <h3 className="text-sm font-bold text-rose-200">Processing Failed</h3>
                        <p className="text-xs text-rose-300 mt-1 font-mono">
                          {activeMeeting.error_message || 'An unexpected error occurred during audio processing.'}
                        </p>
                      </div>
                    </div>

                    {activeMeeting.source === 'upload' && (
                      <div className="pt-2">
                        <button
                          type="button"
                          onClick={handleRetry}
                          disabled={isRetrying}
                          className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-rose-600 hover:bg-rose-500 text-white text-xs font-semibold shadow-md transition-colors disabled:opacity-50"
                        >
                          {isRetrying ? (
                            <>
                              <Loader2 className="w-3.5 h-3.5 animate-spin" />
                              <span>Retrying...</span>
                            </>
                          ) : (
                            <>
                              <RefreshCw className="w-3.5 h-3.5" />
                              <span>Retry Audio Processing</span>
                            </>
                          )}
                        </button>
                      </div>
                    )}
                  </div>
                )}
              </div>

              {/* Bottom Actions */}
              <div className="pt-4 border-t border-slate-800 flex flex-wrap items-center justify-between gap-3">
                <button
                  type="button"
                  onClick={handleIngestAnother}
                  className="inline-flex items-center gap-2 text-xs text-slate-400 hover:text-slate-200 transition-colors"
                >
                  <PlusCircle className="w-3.5 h-3.5" />
                  <span>Ingest Another Meeting</span>
                </button>

                <div className="flex items-center gap-3">
                  <Link
                    to={`/meetings/${activeMeeting.id}/review`}
                    className="inline-flex items-center gap-1.5 text-xs text-indigo-400 hover:text-indigo-300 font-medium"
                  >
                    <span>View Review Page</span>
                    <ChevronRight className="w-3.5 h-3.5" />
                  </Link>
                </div>
              </div>
            </div>
          </div>
        ) : (
          /* Meeting Creation Form */
          <div className="glass-panel rounded-2xl border border-slate-800 overflow-hidden shadow-xl">
            {/* Ingestion Tabs */}
            <div className="flex border-b border-slate-800 bg-slate-900/50 p-1.5 gap-1.5">
              <button
                type="button"
                onClick={() => {
                  setActiveTab('transcript');
                  setError(null);
                }}
                className={`flex-1 flex items-center justify-center gap-2.5 py-3 px-4 rounded-xl text-sm font-semibold transition-all ${
                  activeTab === 'transcript'
                    ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30'
                    : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50'
                }`}
              >
                <FileText className="w-4 h-4" />
                <span>Paste Transcript</span>
              </button>

              <button
                type="button"
                onClick={() => {
                  setActiveTab('upload');
                  setError(null);
                }}
                className={`flex-1 flex items-center justify-center gap-2.5 py-3 px-4 rounded-xl text-sm font-semibold transition-all ${
                  activeTab === 'upload'
                    ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30'
                    : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50'
                }`}
              >
                <UploadCloud className="w-4 h-4" />
                <span>Upload Audio / Video File</span>
              </button>
            </div>

            {/* Form Content */}
            <div className="p-6 sm:p-8">
              {/* TAB 1: PASTE TRANSCRIPT */}
              {activeTab === 'transcript' && (
                <form onSubmit={handleSubmitTranscript} className="space-y-6">
                  <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div className="sm:col-span-2 space-y-1.5">
                      <label className="block text-xs font-semibold text-slate-300 uppercase tracking-wider">
                        Meeting Title <span className="text-rose-400">*</span>
                      </label>
                      <input
                        type="text"
                        value={title}
                        onChange={(e) => setTitle(e.target.value)}
                        placeholder="e.g. Q4 Executive Strategy & Roadmap"
                        required
                        className="w-full px-3.5 py-2.5 bg-slate-900/80 border border-slate-700/80 rounded-xl text-sm text-slate-100 placeholder-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-colors"
                      />
                    </div>

                    <div className="space-y-1.5">
                      <label className="block text-xs font-semibold text-slate-300 uppercase tracking-wider">
                        Meeting Date
                      </label>
                      <input
                        type="date"
                        value={meetingDate}
                        onChange={(e) => setMeetingDate(e.target.value)}
                        className="w-full px-3.5 py-2.5 bg-slate-900/80 border border-slate-700/80 rounded-xl text-sm text-slate-100 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-colors"
                      />
                    </div>
                  </div>

                  <div className="space-y-1.5">
                    <div className="flex items-center justify-between">
                      <label className="block text-xs font-semibold text-slate-300 uppercase tracking-wider">
                        Meeting Transcript <span className="text-rose-400">*</span>
                      </label>
                      <span className="text-xs text-slate-500 font-mono">
                        {transcript.trim() ? `${transcript.trim().split(/\s+/).length} words` : 'Empty'}
                      </span>
                    </div>
                    <textarea
                      rows={10}
                      value={transcript}
                      onChange={(e) => setTranscript(e.target.value)}
                      placeholder="Paste the meeting conversation or transcript here...&#10;&#10;Example:&#10;Bilal: Ahmed, please finish the ABC proposal by Friday.&#10;Ahmed: Understood, I will have it ready for review.&#10;Sarah: I will review vendor contract terms by Wednesday."
                      required
                      className="w-full px-4 py-3 bg-slate-900/80 border border-slate-700/80 rounded-xl text-sm text-slate-100 placeholder-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 font-mono text-xs leading-relaxed transition-colors"
                    />
                  </div>

                  <div className="space-y-1.5">
                    <label className="block text-xs font-semibold text-slate-300 uppercase tracking-wider">
                      Meeting Context & Summary <span className="text-slate-500 font-normal">(Optional)</span>
                    </label>
                    <input
                      type="text"
                      value={summary}
                      onChange={(e) => setSummary(e.target.value)}
                      placeholder="Brief overview or agenda highlights"
                      className="w-full px-3.5 py-2.5 bg-slate-900/80 border border-slate-700/80 rounded-xl text-sm text-slate-100 placeholder-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-colors"
                    />
                  </div>

                  <div className="pt-2 flex items-center justify-end gap-3">
                    <button
                      type="submit"
                      disabled={submitting}
                      className="inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-sm shadow-lg shadow-indigo-600/30 hover:shadow-indigo-500/40 transition-all disabled:opacity-50"
                    >
                      {submitting ? (
                        <>
                          <Loader2 className="w-4 h-4 animate-spin" />
                          <span>Creating Meeting...</span>
                        </>
                      ) : (
                        <>
                          <Sparkles className="w-4 h-4" />
                          <span>Create & Ingest Transcript</span>
                        </>
                      )}
                    </button>
                  </div>
                </form>
              )}

              {/* TAB 2: UPLOAD AUDIO FILE */}
              {activeTab === 'upload' && (
                <form onSubmit={handleSubmitAudio} className="space-y-6">
                  <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div className="sm:col-span-2 space-y-1.5">
                      <label className="block text-xs font-semibold text-slate-300 uppercase tracking-wider">
                        Meeting Title <span className="text-rose-400">*</span>
                      </label>
                      <input
                        type="text"
                        value={title}
                        onChange={(e) => setTitle(e.target.value)}
                        placeholder="e.g. Weekly Product Architecture Review"
                        required
                        className="w-full px-3.5 py-2.5 bg-slate-900/80 border border-slate-700/80 rounded-xl text-sm text-slate-100 placeholder-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-colors"
                      />
                    </div>

                    <div className="space-y-1.5">
                      <label className="block text-xs font-semibold text-slate-300 uppercase tracking-wider">
                        Meeting Date
                      </label>
                      <input
                        type="date"
                        value={meetingDate}
                        onChange={(e) => setMeetingDate(e.target.value)}
                        className="w-full px-3.5 py-2.5 bg-slate-900/80 border border-slate-700/80 rounded-xl text-sm text-slate-100 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-colors"
                      />
                    </div>
                  </div>

                  {/* Drag & Drop File Zone */}
                  <div className="space-y-2">
                    <label className="block text-xs font-semibold text-slate-300 uppercase tracking-wider">
                      Audio / Video Recording <span className="text-rose-400">*</span>
                    </label>

                    <input
                      type="file"
                      ref={fileInputRef}
                      onChange={handleFileSelect}
                      accept=".mp3,.m4a,.wav,.mp4,.webm,.ogg,audio/*,video/*"
                      className="hidden"
                    />

                    {audioFile ? (
                      <div className="p-4 rounded-xl bg-slate-900/80 border border-indigo-500/40 flex items-center justify-between gap-4">
                        <div className="flex items-center gap-3 min-w-0">
                          <div className="w-10 h-10 rounded-lg bg-indigo-600/20 border border-indigo-500/30 flex items-center justify-center text-indigo-400 shrink-0">
                            <FileAudio className="w-5 h-5" />
                          </div>
                          <div className="min-w-0">
                            <p className="text-sm font-semibold text-slate-100 truncate">{audioFile.name}</p>
                            <p className="text-xs text-slate-400">
                              {(audioFile.size / (1024 * 1024)).toFixed(2)} MB • {audioFile.type || 'audio/media'}
                            </p>
                          </div>
                        </div>

                        <button
                          type="button"
                          onClick={() => setAudioFile(null)}
                          className="p-2 rounded-lg text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 transition-colors"
                          title="Remove file"
                        >
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    ) : (
                      <div
                        onDragOver={handleDragOver}
                        onDragLeave={handleDragLeave}
                        onDrop={handleDrop}
                        onClick={() => fileInputRef.current?.click()}
                        className={`border-2 border-dashed rounded-2xl p-8 text-center cursor-pointer transition-all ${
                          dragOver
                            ? 'border-indigo-500 bg-indigo-500/10'
                            : 'border-slate-700/80 hover:border-indigo-500/50 bg-slate-900/40 hover:bg-slate-900/70'
                        }`}
                      >
                        <div className="w-12 h-12 rounded-xl bg-indigo-600/20 border border-indigo-500/30 text-indigo-400 flex items-center justify-center mx-auto mb-3">
                          <UploadCloud className="w-6 h-6" />
                        </div>
                        <p className="text-sm font-semibold text-slate-200">
                          Click to upload or drag & drop audio/video
                        </p>
                        <p className="text-xs text-slate-400 mt-1">
                          MP3, M4A, WAV, MP4, WEBM, OGG (Maximum 500 MB)
                        </p>
                        <div className="mt-4 flex items-center justify-center gap-2">
                          <span className="px-2 py-0.5 rounded text-[11px] font-mono bg-slate-800 text-slate-400">
                            32kbps mono conversion
                          </span>
                          <span className="px-2 py-0.5 rounded text-[11px] font-mono bg-slate-800 text-slate-400">
                            Auto 24MB chunking
                          </span>
                        </div>
                      </div>
                    )}
                  </div>

                  {/* Upload Progress Bar */}
                  {submitting && uploadProgress > 0 && (
                    <div className="space-y-1.5">
                      <div className="flex items-center justify-between text-xs">
                        <span className="text-slate-400 font-medium">Uploading Recording...</span>
                        <span className="text-indigo-400 font-mono font-semibold">{uploadProgress}%</span>
                      </div>
                      <div className="w-full h-2 bg-slate-800 rounded-full overflow-hidden">
                        <div
                          className="h-full bg-gradient-to-r from-indigo-500 to-violet-500 rounded-full transition-all duration-200"
                          style={{ width: `${uploadProgress}%` }}
                        />
                      </div>
                    </div>
                  )}

                  <div className="pt-2 flex items-center justify-end gap-3">
                    <button
                      type="submit"
                      disabled={submitting || !audioFile}
                      className="inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-sm shadow-lg shadow-indigo-600/30 hover:shadow-indigo-500/40 transition-all disabled:opacity-50"
                    >
                      {submitting ? (
                        <>
                          <Loader2 className="w-4 h-4 animate-spin" />
                          <span>{uploadProgress > 0 ? `Uploading (${uploadProgress}%)...` : 'Processing...'}</span>
                        </>
                      ) : (
                        <>
                          <UploadCloud className="w-4 h-4" />
                          <span>Upload & Queue Audio</span>
                        </>
                      )}
                    </button>
                  </div>
                </form>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );
};
