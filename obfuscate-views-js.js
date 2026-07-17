const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const JavaScriptObfuscator = require('javascript-obfuscator');

const viewsDir = process.argv[2];
const projectRoot = process.argv[3];
if (!viewsDir || !projectRoot) {
    console.error('Usage: node obfuscate-views-js.js <views-directory> <laravel-project-root-with-vendor>');
    process.exit(1);
}

const verifyScript = path.join(__dirname, 'verify-blade.php');

const options = {
    compact: true,
    controlFlowFlattening: true,
    controlFlowFlatteningThreshold: 0.75,
    deadCodeInjection: true,
    deadCodeInjectionThreshold: 0.4,
    identifierNamesGenerator: 'hexadecimal',
    // Never rename top-level functions/vars: they may be called from
    // onclick="" attributes elsewhere in the same HTML, or from a later
    // <script> block in the same file processed independently of this one.
    renameGlobals: false,
    // Skip selfDefending: known to misfire under some minify/CDN setups,
    // not worth the reliability risk here.
    selfDefending: false,
    stringArray: true,
    stringArrayEncoding: ['base64'],
    stringArrayThreshold: 0.75,
    // splitStrings is deliberately OFF: it chops string literal content into
    // chunks joined by concatenation, which can corrupt literal Blade syntax
    // (e.g. "{{ route('x') }}") embedded inside a JS string. Even with this
    // off, other transforms can still collide with Blade in ways that pass
    // JS parsing but break Blade's own regex-based compiler - that's what
    // the per-file verify-and-rollback step below is for.
    splitStrings: false,
    // Object keys can be data contracts with server-side JSON - leave alone.
    transformObjectKeys: false,
};

const scriptBlockRegex = /<script(\s[^>]*)?>([\s\S]*?)<\/script>/gi;

function shouldSkip(attrs) {
    if (!attrs) return false;
    if (/\bsrc\s*=/i.test(attrs)) return true;
    const typeMatch = attrs.match(/\btype\s*=\s*["']([^"']*)["']/i);
    if (typeMatch && !/javascript/i.test(typeMatch[1])) return true;
    return false;
}

function compilesSafely(filePath) {
    const result = spawnSync('php', [verifyScript, filePath, projectRoot], { encoding: 'utf8' });
    return result.status === 0;
}

let filesChanged = 0;
let filesRolledBack = 0;
let blocksObfuscated = 0;
let blocksSkipped = 0;

function walk(dir) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            walk(full);
        } else if (entry.name.endsWith('.blade.php')) {
            processFile(full);
        }
    }
}

function processFile(filePath) {
    const original = fs.readFileSync(filePath, 'utf8');
    let changed = false;

    const updated = original.replace(scriptBlockRegex, (match, attrs, code) => {
        if (shouldSkip(attrs) || !code.trim()) {
            return match;
        }

        try {
            const result = JavaScriptObfuscator.obfuscate(code, options).getObfuscatedCode();
            changed = true;
            blocksObfuscated++;
            return `<script${attrs || ''}>${result}</script>`;
        } catch (e) {
            blocksSkipped++;
            console.log(`SKIP (left as-is, failed to parse as plain JS): ${filePath} - ${e.message.split('\n')[0]}`);
            return match;
        }
    });

    if (!changed) {
        return;
    }

    fs.writeFileSync(filePath, updated, 'utf8');

    if (compilesSafely(filePath)) {
        filesChanged++;
    } else {
        fs.writeFileSync(filePath, original, 'utf8');
        filesRolledBack++;
        console.log(`ROLLED BACK (obfuscation broke Blade compilation): ${filePath}`);
    }
}

walk(viewsDir);

console.log(`Done. Files changed: ${filesChanged}, rolled back: ${filesRolledBack}, blocks obfuscated: ${blocksObfuscated}, blocks skipped: ${blocksSkipped}`);