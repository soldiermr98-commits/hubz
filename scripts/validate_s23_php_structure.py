#!/usr/bin/env python3
"""اعتبارسنج ساختاری PHP: توازن براکت‌ها بیرون از رشته/کامنت/هرداک + کنترل‌های منطقی پایه."""
import sys, re

def strip_php(code: str) -> str:
    out = []
    i, n = 0, len(code)
    state = None  # None | 'line' | 'block' | 'sq' | 'dq' | 'heredoc'
    while i < n:
        ch = code[i]
        nxt = code[i+1] if i + 1 < n else ''
        if state is None:
            if ch == '/' and nxt == '/':
                state = 'line'; i += 2; continue
            if ch == '#':
                state = 'line'; i += 1; continue
            if ch == '/' and nxt == '*':
                state = 'block'; i += 2; continue
            if ch == "'":
                state = 'sq'; i += 1; out.append("'"); continue
            if ch == '"':
                state = 'dq'; i += 1; out.append('"'); continue
            m = re.match(r'<<<[ \t]*([\'"]?)([A-Za-z_][A-Za-z0-9_]*)\1\r?\n', code[i:])
            if m:
                state = ('heredoc', m.group(2)); i += m.end(); continue
            out.append(ch); i += 1; continue
        if state == 'line':
            if ch == '\n':
                state = None; out.append(ch)
            i += 1; continue
        if state == 'block':
            if ch == '*' and nxt == '/':
                state = None; i += 2; continue
            i += 1; continue
        if state == 'sq':
            if ch == '\\':
                i += 2; continue
            if ch == "'":
                state = None; out.append("'")
            i += 1; continue
        if state == 'dq':
            if ch == '\\':
                i += 2; continue
            if ch == '"':
                state = None; out.append('"')
            i += 1; continue
        if isinstance(state, tuple) and state[0] == 'heredoc':
            tag = state[1]
            m = re.match(r'\r?\n[ \t]*' + re.escape(tag) + r'\b', code[i:])
            if m:
                state = None; i += m.end(); continue
            i += 1; continue
    if state is not None:
        raise ValueError(f'unterminated state: {state}')
    return ''.join(out)

PAIRS = {')': '(', ']': '[', '}': '{'}
OPENERS = set('([{')

def check(path: str) -> list[str]:
    problems = []
    with open(path, encoding='utf-8') as f:
        code = f.read()
    try:
        stripped = strip_php(code)
    except ValueError as e:
        return [f'{path}: {e}']
    stack = []
    lines_map = []
    line = 1
    for ch in stripped:
        if ch == '\n':
            line += 1
        lines_map.append((ch, line))
    for ch, ln in lines_map:
        if ch in OPENERS:
            stack.append((ch, ln))
        elif ch in PAIRS:
            if not stack or stack[-1][0] != PAIRS[ch]:
                problems.append(f'{path}:{ln}: براکت نامتوازن «{ch}»')
                if stack: stack.pop()
            else:
                stack.pop()
    for ch, ln in stack:
        problems.append(f'{path}:{ln}: «{ch}» بسته نشده')
    return problems

checks = {
    'app/Core/RateLimit.php': [
        ('final class RateLimit', 'class'),
        ('allow(', 'entry'),
        ('gcStaleWindows', 'gc'),
    ],
    'app/Services/MediaService.php': [
        ('final class MediaService', 'class'),
        ('MediaLibraryService::listLibrary', 'delegate-list'),
        ('MediaLibraryService::deleteMediaItems', 'delegate-delete'),
        ('acquireLibraryLock(true)', 'upload-lock'),
        ('releaseLibraryLock($libraryLock)', 'unlock-finally'),
        ('animatedGifFrameCount', 'gif-scan'),
    ],
    'app/Services/MediaLibraryService.php': [
        ('deleteMediaItems(array $mediaIds, bool $detachUsages = false, bool $force = false)', 'force-signature'),
        ('UPDATE advertising_banners SET media_id = NULL, active = 0 WHERE media_id = :id', 'force-detach'),
        ('purgeOrphanUploadFilesLocked', 'purge-lock'),
        ("'skipped' => true", 'purge-skip'),
    ],
    'app/Controllers/PublicSiteController.php': [
        ("RateLimit::allow('events-track'", 'track-ratelimit'),
    ],
    'app/Controllers/BusinessController.php': [
        ("in_array($requestedStatus, ['published', 'hidden', 'rejected'], true)", 'editor-gating'),
    ],
}

bad = False
for path, tokens in checks.items():
    probs = check(path)
    for p in probs:
        print('✗', p); bad = True
    src = open(path, encoding='utf-8').read()
    for token, label in tokens:
        if token not in src:
            print(f'✗ {path}: انتظار «{label}» دیده نشد'); bad = True
    if not any(p.startswith('✗') for p in [str(x) for x in probs]):
        print(f'✓ {path}: ساختار سالم')

sys.exit(1 if bad else 0)
