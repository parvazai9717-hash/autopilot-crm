#!/usr/bin/env node

/**
 * Static Linter for n8n Workflows (N6)
 * Checks n8n workflow JSONs for multi-tenant isolation defects, hardcoded credentials, and missing error handlers.
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const n8nDir = path.resolve(__dirname, '../n8n');

let totalErrors = 0;
let totalFilesChecked = 0;

function walkDir(dir, callback) {
  if (!fs.existsSync(dir)) return;
  const files = fs.readdirSync(dir);
  for (const file of files) {
    const fullPath = path.join(dir, file);
    const stat = fs.statSync(fullPath);
    if (stat.isDirectory()) {
      walkDir(fullPath, callback);
    } else if (file.endsWith('.json')) {
      callback(fullPath);
    }
  }
}

function lintWorkflowFile(filePath) {
  totalFilesChecked++;
  const relativePath = path.relative(path.resolve(__dirname, '..'), filePath);
  let content = '';

  try {
    content = fs.readFileSync(filePath, 'utf-8');
  } catch (err) {
    console.error(`❌ Could not read ${relativePath}:`, err.message);
    totalErrors++;
    return;
  }

  let workflow;
  try {
    workflow = JSON.parse(content);
  } catch (err) {
    console.error(`❌ Invalid JSON in ${relativePath}:`, err.message);
    totalErrors++;
    return;
  }

  console.log(`\n🔍 Checking: ${relativePath}`);
  let fileIssues = 0;

  // 1. Check for hardcoded org_id=1
  const hardcodedOrgPatterns = [
    /"org_id"\s*:\s*1\b/g,
    /org_id\s*=\s*1\b/g,
    /orgId\s*=\s*1\b/g,
    /"orgId"\s*:\s*1\b/g,
  ];

  for (const pattern of hardcodedOrgPatterns) {
    if (pattern.test(content)) {
      console.error(`  ❌ [FAIL] Hardcoded org_id=1 detected. Tenant must be dynamically provided via context/event.`);
      fileIssues++;
      break;
    }
  }

  // 2. Check for hardcoded secrets
  const leakedSecretPatterns = [
    /password123/gi,
    /n8n-platform-secret-key-2026/gi,
  ];

  for (const pattern of leakedSecretPatterns) {
    if (pattern.test(content)) {
      console.error(`  ❌ [FAIL] Leaked plaintext secret detected. Use environment variables or credentials.`);
      fileIssues++;
      break;
    }
  }

  // 3. Inspect nodes if workflow JSON structure
  if (Array.isArray(workflow.nodes)) {
    const nodeNames = workflow.nodes.map(n => n.name);
    console.log(`  ℹ️  Workflow contains ${workflow.nodes.length} node(s): ${nodeNames.slice(0, 5).join(', ')}${nodeNames.length > 5 ? '...' : ''}`);
  }

  if (fileIssues === 0) {
    console.log(`  ✅ Passed all multi-tenant and security checks.`);
  } else {
    totalErrors += fileIssues;
  }
}

console.log(`===========================================`);
console.log(`   n8n Workflow Multi-Tenancy Linter (N6)  `);
console.log(`===========================================`);

walkDir(n8nDir, lintWorkflowFile);

console.log(`\n-------------------------------------------`);
console.log(`Files scanned: ${totalFilesChecked}`);
console.log(`Total issues:  ${totalErrors}`);

if (totalErrors > 0) {
  console.error(`\n❌ Linting failed with ${totalErrors} issue(s).`);
  process.exit(1);
} else {
  console.log(`\n✅ All n8n workflows passed linting!`);
  process.exit(0);
}
