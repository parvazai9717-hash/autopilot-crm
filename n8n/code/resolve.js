/**
 * Resolves an action item's raw owner string against an organization user roster.
 *
 * Fixes Defect F5:
 * Previously, an unmatched name resulted in { owner_id: null, owner_ambiguous: false },
 * which slipped past approval filters.
 *
 * Now:
 * - Exactly 1 match: { owner_id: user.id, owner_state: 'resolved', owner_ambiguous: false }
 * - Multiple matches: { owner_id: null, owner_state: 'ambiguous', owner_ambiguous: true, candidates: [...] }
 * - 0 matches (unmatched name): { owner_id: null, owner_state: 'unmatched', owner_ambiguous: false, owner_name_raw: name }
 * - No name provided: { owner_id: null, owner_state: 'missing', owner_ambiguous: false }
 */
function resolveOwner(rawName, users = []) {
  if (!rawName || typeof rawName !== 'string' || !rawName.trim()) {
    return {
      owner_id: null,
      owner_name_raw: null,
      owner_state: 'missing',
      owner_ambiguous: false,
      candidates: [],
    };
  }

  const cleaned = rawName.trim();
  const lower = cleaned.toLowerCase();

  // Normalize user names & emails for matching
  const matches = users.filter((u) => {
    if (!u) return false;
    const nameLower = (u.name || '').toLowerCase().trim();
    const emailLower = (u.email || '').toLowerCase().trim();
    const emailPrefix = emailLower.split('@')[0];
    const firstName = nameLower.split(/\s+/)[0];

    // 1. Exact full name or exact email
    if (nameLower === lower || emailLower === lower) return true;

    // 2. Exact first name
    if (firstName === lower) return true;

    // 3. Email username prefix
    if (emailPrefix === lower) return true;

    // 4. Starts with or includes
    if (nameLower.startsWith(lower + ' ') || nameLower.endsWith(' ' + lower)) return true;

    return false;
  });

  if (matches.length === 1) {
    return {
      owner_id: matches[0].id,
      owner_name_raw: cleaned,
      owner_state: 'resolved',
      owner_ambiguous: false,
      candidates: [matches[0].id],
    };
  }

  if (matches.length > 1) {
    return {
      owner_id: null,
      owner_name_raw: cleaned,
      owner_state: 'ambiguous',
      owner_ambiguous: true,
      candidates: matches.map((m) => m.id),
    };
  }

  // 0 matches: Unmatched name (F5 fix)
  return {
    owner_id: null,
    owner_name_raw: cleaned,
    owner_state: 'unmatched',
    owner_ambiguous: false,
    candidates: [],
  };
}

module.exports = { resolveOwner };
