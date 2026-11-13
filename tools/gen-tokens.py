"""Generate public/assets/css/tokens.css and docs/ui-contrast.md.

Every text/background pair is darkened (light theme) or lightened (dark theme)
until it passes WCAG AA, so the palette stays readable for all 5 accents x 2 themes.
Run: python tools/gen-tokens.py
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# ---------- color helpers ----------

def hex2rgb(h):
    h = h.lstrip('#')
    return tuple(int(h[i:i + 2], 16) for i in (0, 2, 4))

def rgb2hex(c):
    return '#' + ''.join(f'{max(0, min(255, round(v))):02x}' for v in c)

def mix(a, b, t):
    """t=0 -> a, t=1 -> b."""
    a, b = hex2rgb(a), hex2rgb(b)
    return rgb2hex([a[i] + (b[i] - a[i]) * t for i in range(3)])

def over(fg, alpha, bg):
    """Composite fg with alpha over solid bg."""
    return mix(bg, fg, alpha)

def lum(h):
    def ch(v):
        v /= 255
        return v / 12.92 if v <= 0.03928 else ((v + 0.055) / 1.055) ** 2.4
    r, g, b = (ch(v) for v in hex2rgb(h))
    return 0.2126 * r + 0.7152 * g + 0.0722 * b

def ratio(a, b):
    la, lb = sorted((lum(a), lum(b)), reverse=True)
    return (la + 0.05) / (lb + 0.05)

def push(color, toward, backgrounds, target=4.6):
    """Move color toward black/white until it reaches target on every background."""
    t = 0.0
    out = color
    while min(ratio(out, bg) for bg in backgrounds) < target and t < 1:
        t += 0.02
        out = mix(color, toward, t)
    return out

def rgba(h, a):
    r, g, b = hex2rgb(h)
    return f'rgba({r}, {g}, {b}, {a})'

# ---------- neutrals ----------

NEUTRAL = {
    'light': {
        'bg': '#f4f5fb', 'surface': '#ffffff', 'surface-muted': '#f7f8fd', 'surface-sunken': '#eef0f8',
        'text': '#171c2e', 'text-muted': '#555d75', 'border': '#dde1ee', 'border-strong': '#868fa8',
        'divider': '#eaedf5', 'white': '#ffffff',
    },
    'dark': {
        'bg': '#0e1322', 'surface': '#151b2d', 'surface-muted': '#1a2135', 'surface-sunken': '#11172a',
        'text': '#e8ebf4', 'text-muted': '#a4acc3', 'border': '#283048', 'border-strong': '#68728f',
        'divider': '#212840', 'white': '#ffffff',
    },
}
GLASS = {
    'light': {'topbar-bg': rgba('#ffffff', .78), 'glass-bg': rgba('#ffffff', .74), 'sheen': rgba('#ffffff', .28),
              'shadow-color': '23, 28, 46'},
    'dark': {'topbar-bg': rgba('#0e1322', .74), 'glass-bg': rgba('#151b2d', .72), 'sheen': rgba('#ffffff', .16),
             'shadow-color': '0, 0, 0'},
}

# ---------- semantic tones: light base, dark base (brighter) ----------

TONES = {
    'indigo': ('#4f46e5', '#818cf8'),
    'sky': ('#0284c7', '#38bdf8'),
    'emerald': ('#059669', '#34d399'),
    'amber': ('#d97706', '#fbbf24'),
    'rose': ('#e11d48', '#fb7185'),
    'violet': ('#7c3aed', '#a78bfa'),
    'teal': ('#0d9488', '#2dd4bf'),
    'orange': ('#ea580c', '#fb923c'),
    'slate': ('#64748b', '#94a3b8'),
}

# ---------- accents: brand-1, brand-2, brand-3 (light / dark) ----------

ACCENTS = {
    'indigo': {'name': 'Indigo', 'light': ('#5b5bd6', '#0ea5e9', '#d946ef'), 'dark': ('#8b8cf6', '#38bdf8', '#e879f9')},
    'ocean': {'name': 'Xanh biển', 'light': ('#2563eb', '#0891b2', '#6366f1'), 'dark': ('#60a5fa', '#22d3ee', '#818cf8')},
    'forest': {'name': 'Xanh lá', 'light': ('#059669', '#0d9488', '#65a30d'), 'dark': ('#34d399', '#2dd4bf', '#a3e635')},
    'sunset': {'name': 'Cam', 'light': ('#ea580c', '#d97706', '#e11d48'), 'dark': ('#fb923c', '#fbbf24', '#fb7185')},
    'rose': {'name': 'Hồng', 'light': ('#e11d48', '#db2777', '#a855f7'), 'dark': ('#fb7185', '#f472b6', '#c084fc')},
}


# Dark base -> matching light base, used for button stops.
LIGHT_OF = {d: l for l, d in TONES.values()}
for _acc in ACCENTS.values():
    LIGHT_OF.update(dict(zip(_acc['dark'], _acc['light'])))


def tone_set(base, theme):
    """Return (x, soft, strong, border, soft_solid) for a tone."""
    n = NEUTRAL[theme]
    if theme == 'light':
        soft = mix(base, '#ffffff', .88)
        strong = push(base, '#000000', [soft, n['surface'], n['bg']], 6.0)
        border = mix(base, '#ffffff', .55)
        return base, soft, strong, border, soft
    soft_solid = over(base, .18, n['surface'])
    strong = push(base, '#ffffff', [soft_solid, n['surface'], n['bg']], 7.0)
    return base, rgba(base, .18), strong, rgba(base, .45), soft_solid


def button_stop(color, theme):
    """Gradient stop dark enough for white text (AA). Dark theme reuses the
    saturated light-theme hue so buttons stay vivid instead of muddy."""
    if theme == 'dark':
        color = LIGHT_OF.get(color, color)
    return push(color, '#000000', ['#ffffff'], 4.7)


def neutral_block(theme):
    lines = [f'    --{k}: {v};' for k, v in NEUTRAL[theme].items()]
    g = GLASS[theme]
    lines += [f'    --topbar-bg: {g["topbar-bg"]};', f'    --glass-bg: {g["glass-bg"]};', f'    --sheen: {g["sheen"]};']
    sc = g['shadow-color']
    k = 1 if theme == 'light' else 3
    lines += [
        f'    --shadow-sm: 0 1px 2px rgba({sc}, {.05 * k:.2f}), 0 1px 3px rgba({sc}, {.06 * k:.2f});',
        f'    --shadow-md: 0 4px 14px -4px rgba({sc}, {.12 * k:.2f});',
        f'    --shadow-lg: 0 18px 40px -12px rgba({sc}, {.22 * k:.2f});',
    ]
    for name, (lb, db) in TONES.items():
        x, soft, strong, border, solid = tone_set(lb if theme == 'light' else db, theme)
        lines += [f'    --{name}: {x};', f'    --{name}-soft: {soft};', f'    --{name}-strong: {strong};',
                  f'    --{name}-border: {border};', f'    --{name}-soft-solid: {solid};']
    # Danger button gradient (rose -> red), white text.
    rose = TONES['rose'][0]
    lines += [f'    --danger-from: {button_stop(rose, theme)};', f'    --danger-to: {button_stop("#dc2626", theme)};']
    return lines


def accent_block(key, theme):
    b1, b2, b3 = ACCENTS[key][theme]
    _, s1, k1, r1, _ = tone_set(b1, theme)
    _, s2, k2, r2, _ = tone_set(b2, theme)
    pf, pt = button_stop(b1, theme), button_stop(b2, theme)
    a = (.10, .08, .06) if theme == 'light' else (.20, .16, .12)
    blob = (.30, .24, .20) if theme == 'light' else (.34, .26, .22)
    return [
        f'    --brand-1: {b1};', f'    --brand-2: {b2};', f'    --brand-3: {b3};',
        f'    --brand-1-soft: {s1};', f'    --brand-1-strong: {k1};', f'    --brand-1-border: {r1};',
        f'    --brand-2-soft: {s2};', f'    --brand-2-strong: {k2};', f'    --brand-2-border: {r2};',
        f'    --primary-from: {pf};', f'    --primary-to: {pt};',
        f'    --glow-1: {rgba(b1, a[0])};', f'    --glow-2: {rgba(b2, a[1])};', f'    --glow-3: {rgba(b3, a[2])};',
        f'    --blob-1: {rgba(b1, blob[0])};', f'    --blob-2: {rgba(b2, blob[1])};', f'    --blob-3: {rgba(b3, blob[2])};',
        f'    --surface-tint: {rgba(b1, .03 if theme == "light" else .05)};',
        f'    --surface-tint-2: {rgba(b2, .03 if theme == "light" else .04)};',
    ]


SHARED = '''    /* Gradients (built from the accent tokens above). */
    --grad-brand: linear-gradient(135deg, var(--brand-1), var(--brand-2));
    --grad-accent: linear-gradient(135deg, var(--brand-1), var(--brand-3));
    --grad-primary: linear-gradient(135deg, var(--primary-from), var(--primary-to));
    --grad-danger: linear-gradient(135deg, var(--danger-from), var(--danger-to));
    --grad-surface: linear-gradient(180deg, var(--surface-tint), var(--surface-tint-2));
    --primary: var(--primary-from);
    --on-primary: #ffffff;

    /* Shape, type, motion */
    --radius-sm: 8px;
    --radius-md: 12px;
    --radius-lg: 16px;
    --font: "Be Vietnam Pro", "Segoe UI", system-ui, -apple-system, Roboto, Arial, sans-serif;
    --headline: 1.75rem;
    --title: 1.2rem;
    --subtitle: 1.05rem;
    --body: 1rem;
    --label: .875rem;
    --caption: .8125rem;
    --ease: cubic-bezier(.2, .7, .2, 1);
    --container: 1200px;'''


def block(selector, lines):
    return selector + ' {\n' + '\n'.join(lines) + '\n}\n'


def build_css():
    out = ['/* GENERATED by tools/gen-tokens.py - edit the script, not this file.\n'
           '   Light = :root, dark = [data-theme="dark"] plus a prefers-color-scheme fallback.\n'
           '   Accent = [data-accent], it only overrides the --brand-* / --primary-* tokens. */\n']
    out.append(block(':root', ['    color-scheme: light;'] + neutral_block('light') + accent_block('indigo', 'light') + [SHARED]))
    dark = ['    color-scheme: dark;'] + neutral_block('dark') + accent_block('indigo', 'dark')
    out.append(block(':root[data-theme="dark"]', dark))
    out.append('@media (prefers-color-scheme: dark) {\n' + block(':root:not([data-theme="light"])', dark).replace('\n', '\n    ').rstrip() + '\n}\n')
    for key in ACCENTS:
        if key == 'indigo':
            continue
        out.append(block(f':root[data-accent="{key}"]', accent_block(key, 'light')))
    for key in ACCENTS:
        if key == 'indigo':
            continue
        lines = accent_block(key, 'dark')
        out.append(block(f':root[data-theme="dark"][data-accent="{key}"]', lines))
        out.append('@media (prefers-color-scheme: dark) {\n'
                   + block(f':root:not([data-theme="light"])[data-accent="{key}"]', lines).replace('\n', '\n    ').rstrip() + '\n}\n')
    return '\n'.join(out)


def build_report():
    rows = []
    worst = (99, '')

    def add(theme, accent, label, fg, bg, need):
        nonlocal worst
        r = ratio(fg, bg)
        rows.append((theme, accent, label, r, need))
        if r / need < worst[0]:
            worst = (r / need, f'{label} · {accent} · {theme}: {r:.2f}:1 (cần {need})')

    for theme in ('light', 'dark'):
        n = NEUTRAL[theme]
        for acc in ACCENTS:
            b1, b2, _ = ACCENTS[acc][theme]
            _, _, k1, _, s1 = tone_set(b1, theme)
            _, _, k2, _, s2 = tone_set(b2, theme)
            add(theme, acc, 'Chữ trắng / nút primary (đầu 1)', '#ffffff', button_stop(b1, theme), 4.5)
            add(theme, acc, 'Chữ trắng / nút primary (đầu 2)', '#ffffff', button_stop(b2, theme), 4.5)
            add(theme, acc, 'Link brand / nền card', k1, n['surface'], 4.5)
            add(theme, acc, 'Tab active (brand-1-strong / soft)', k1, s1, 4.5)
            add(theme, acc, 'Khối demo (brand-2-strong / soft)', k2, s2, 4.5)
            add(theme, acc, 'Vòng focus / nền card', button_stop(b1, theme), n['surface'], 3)
        add(theme, 'mọi accent', 'Chữ chính / nền trang', n['text'], n['bg'], 4.5)
        add(theme, 'mọi accent', 'Chữ phụ / nền card', n['text-muted'], n['surface'], 4.5)
        add(theme, 'mọi accent', 'Chữ phụ / nền trang', n['text-muted'], n['bg'], 4.5)
        add(theme, 'mọi accent', 'Viền ô nhập / nền card', n['border-strong'], n['surface'], 3)
        rose = TONES['rose'][0]
        add(theme, 'mọi accent', 'Chữ trắng / nút danger (đầu 1)', '#ffffff', button_stop(rose, theme), 4.5)
        add(theme, 'mọi accent', 'Chữ trắng / nút danger (đầu 2)', '#ffffff', button_stop('#dc2626', theme), 4.5)
        for name, (lb, db) in TONES.items():
            x, _, strong, _, soft = tone_set(lb if theme == 'light' else db, theme)
            add(theme, 'mọi accent', f'Pill/alert/avatar/card {name} (chữ / nền soft)', strong, soft, 4.5)
            add(theme, 'mọi accent', f'Icon card {name} / nền soft', x if theme == 'dark' else strong, soft, 3)

    head = ('# Bảng tương phản màu (WCAG AA)\n\n'
            'Sinh tự động bởi `tools/gen-tokens.py`. Chữ thường cần ≥ 4.5:1, viền/icon quan trọng cần ≥ 3:1.\n'
            'Nền soft ở chế độ tối là màu alpha 18% nên được tính sau khi phủ lên nền card.\n\n'
            f'**Trường hợp thấp nhất (so với ngưỡng):** {worst[1]}\n\n'
            f'**Số cặp kiểm tra:** {len(rows)} · **Không đạt:** {sum(1 for r in rows if r[3] < r[4])}\n\n'
            '| Theme | Accent | Cặp màu | Tỉ lệ | Ngưỡng | Kết quả |\n|---|---|---|---|---|---|\n')
    body = ''.join(f'| {t} | {a} | {l} | {r:.2f}:1 | {need}:1 | {"Đạt" if r >= need else "KHÔNG ĐẠT"} |\n'
                   for t, a, l, r, need in rows)
    return head + body, worst, rows


if __name__ == '__main__':
    import sys
    sys.stdout.reconfigure(encoding='utf-8')
    (ROOT / 'public/assets/css/tokens.css').write_text(build_css(), encoding='utf-8', newline='\n')
    report, worst, rows = build_report()
    (ROOT / 'docs').mkdir(exist_ok=True)
    (ROOT / 'docs/ui-contrast.md').write_text(report, encoding='utf-8', newline='\n')
    fails = [r for r in rows if r[3] < r[4]]
    print(f'pairs={len(rows)} fails={len(fails)} worst={worst[1]}')
    for f in fails:
        print('FAIL', f)
