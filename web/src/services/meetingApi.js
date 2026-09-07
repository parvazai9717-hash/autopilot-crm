import api from './api';

export const meetingApi = {
  // Fetch meeting review details, tasks, org roster, stats
  getMeetingReview: async (meetingId) => {
    const response = await api.get(`/api/meetings/${meetingId}/review`);
    return response.data;
  },

  // Inline update task fields
  updateTask: async (taskId, data) => {
    const response = await api.patch(`/api/tasks/${taskId}`, data);
    return response.data;
  },

  // Approve a single task card
  approveTask: async (taskId) => {
    const response = await api.post(`/api/tasks/${taskId}/approve`);
    return response.data;
  },

  // Reject a single task card
  rejectTask: async (taskId, reason = null) => {
    const response = await api.post(`/api/tasks/${taskId}/reject`, { reason });
    return response.data;
  },

  // Approve all eligible tasks in meeting, skipping ambiguous cards
  approveAll: async (meetingId) => {
    const response = await api.post(`/api/meetings/${meetingId}/approve-all`);
    return response.data;
  },

  // Create meeting from pasted transcript (Admin)
  createTranscriptMeeting: async (payload) => {
    const response = await api.post('/api/meetings', payload);
    return response.data;
  },

  // Upload meeting recording audio/video (Admin)
  uploadAudio: async (formData, onProgress) => {
    const response = await api.post('/api/meetings/upload', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
      onUploadProgress: (progressEvent) => {
        if (onProgress && progressEvent.total) {
          const percent = Math.round((progressEvent.loaded * 100) / progressEvent.total);
          onProgress(percent);
        }
      },
    });
    return response.data;
  },

  // Live polling of meeting processing status
  getStatus: async (meetingId) => {
    const response = await api.get(`/api/meetings/${meetingId}/status`);
    return response.data;
  },

  // Retry stuck or failed audio processing
  retryMeeting: async (meetingId) => {
    const response = await api.post(`/api/meetings/${meetingId}/retry`);
    return response.data;
  },
};
