#!/usr/bin/env node
/**
 * lucide-subset.js — পূর্ণ আইকন বান্ডল (assets/js/lucide-full.js, ~588 KB) থেকে শুধু
 * সাইটে ব্যবহৃত আইকন নিয়ে ছোট assets/js/lucide.js বানায়।
 *
 * চালানো (প্রজেক্ট রুট থেকে):  node build/lucide-subset.js
 *
 * কোন আইকন লাগবে — নিজে থেকেই বের করে: সব .php ফাইলের প্রতিটা কোট-করা শব্দ
 * ('calendar-check', "x" …) যেটা বান্ডলের কোনো আইকনের নামের সাথে মেলে। অর্থাৎ
 * data-lucide="…" ছাড়াও 'icon' => '…' জাতীয় ডাইনামিক নামও ধরা পড়ে। কিছু বাড়তি
 * আইকন ঢুকলে ক্ষতি নেই (কয়েক KB), বাদ পড়লেই সমস্যা।
 *
 * নিরাপত্তা-জাল: ছোট ফাইলের createIcons() শেষে যদি কোনো <i data-lucide> না বদলে
 * থাকে, তখন নিজে থেকেই lucide-full.js নামিয়ে আবার আঁকে — আইকন কখনো হারায় না।
 */
'use strict';
const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '..');
const SRC = path.join(root, 'assets/js/lucide-full.js');
const OUT = path.join(root, 'assets/js/lucide.js');

const lines = fs.readFileSync(SRC, 'utf8').split('\n');
const find = (re, from = 0) => { for (let i = from; i < lines.length; i++) if (re.test(lines[i])) return i; throw new Error('not found: ' + re); };

const iDefEnd  = find(/^  };/, find(/^  const defaultAttributes = \{/));   // হেডার + রানটাইমের শেষ
const iMap     = find(/^  var iconAndAliases = /);
const iMapEnd  = find(/^  \}\);/, iMap);
const iCreate  = find(/^  const createIcons = /);
const iCreateEnd = find(/^  };/, iCreate);

// আইকনের সংজ্ঞা: "  const Name = [" … "  ];"
const defs = {};
for (let i = iDefEnd + 1; i < iMap; i++) {
    const one = /^  const (\w+) = \[.*\];$/.exec(lines[i]);          // এক লাইনের ছোট আইকন
    if (one) { defs[one[1]] = lines[i]; continue; }
    const m = /^  const (\w+) = \[$/.exec(lines[i]);
    if (!m) continue;
    const end = find(/^  \];/, i);
    defs[m[1]] = lines.slice(i, end + 1).join('\n');
    i = end;
}
// নাম → আসল সংজ্ঞা (alias সহ)
const map = {};
for (let i = iMap + 1; i < iMapEnd; i++) {
    const m = /^    (\w+): (\w+),?$/.exec(lines[i]);
    if (m) map[m[1]] = m[2];
}
const pascal = (s) => s.replace(/(\w)(\w*)(_|-|\s*)/g, (g0, g1, g2) => g1.toUpperCase() + g2.toLowerCase());

// PHP ফাইল স্ক্যান
const skipDirs = new Set(['.git', 'node_modules', 'uploads', 'storage', 'database', 'build']);
const used = new Set();
(function walk(dir) {
    for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
        if (ent.isDirectory()) { if (!skipDirs.has(ent.name)) walk(path.join(dir, ent.name)); continue; }
        if (!ent.name.endsWith('.php')) continue;
        const txt = fs.readFileSync(path.join(dir, ent.name), 'utf8');
        for (const m of txt.matchAll(/['"]([a-z][a-z0-9]*(?:-[a-z0-9]+)*)['"]/g)) {
            const key = pascal(m[1]);
            if (map[key]) used.add(key);
        }
    }
})(root);

const keys = [...used].sort();
const targets = [...new Set(keys.map((k) => map[k]))].sort();
const missing = targets.filter((t) => !defs[t]);
if (missing.length) throw new Error('definition missing: ' + missing.join(', '));

const fallback = `
  // ── নিরাপত্তা-জাল: এই ছোট ফাইলে নেই এমন আইকন পেলে পূর্ণ বান্ডল নামিয়ে আবার আঁকা ──
  var fullSrc = (function () {
    try { var s = document.currentScript && document.currentScript.src; return s ? s.replace(/lucide\\.js(\\?.*)?$/, 'lucide-full.js') : ''; } catch (e) { return ''; }
  })();
  var fullAsked = false;
  const createIconsSafe = (opts) => {
    createIcons(opts);
    try {
      if (fullAsked || !fullSrc || !document.querySelector('i[data-lucide]')) return;
      fullAsked = true;
      var el = document.createElement('script');
      el.src = fullSrc;
      el.onload = function () { try { if (window.lucide && window.lucide.createIcons) window.lucide.createIcons(opts); } catch (e) {} };
      document.head.appendChild(el);
    } catch (e) {}
  };
`;

const out = [
    lines.slice(0, iDefEnd + 1).join('\n').replace('@license lucide', '@license lucide (subset: ' + keys.length + ' icons — build/lucide-subset.js দিয়ে তৈরি, হাতে এডিট করবেন না)'),
    '',
    targets.map((t) => defs[t]).join('\n\n'),
    '',
    '  var iconAndAliases = /*#__PURE__*/Object.freeze({',
    '    __proto__: null,',
    keys.map((k) => `    ${k}: ${map[k]}`).join(',\n'),
    '  });',
    '',
    lines.slice(iCreate, iCreateEnd + 1).join('\n'),
    fallback,
    '  exports.createElement = createElement$1;',
    '  exports.createIcons = createIconsSafe;',
    '  exports.icons = iconAndAliases;',
    '',
    '}));',
    '',
].join('\n');
fs.writeFileSync(OUT, out);
console.log(`icons: ${keys.length} names → ${targets.length} definitions`);
console.log(`size : ${(fs.statSync(SRC).size / 1024).toFixed(0)} KB → ${(fs.statSync(OUT).size / 1024).toFixed(1)} KB`);
