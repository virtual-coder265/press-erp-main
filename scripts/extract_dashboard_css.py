from pathlib import Path

root = Path(__file__).resolve().parents[1]
index_path = root / "modules/dashboard/index.php"
css_path = root / "assets/css/dashboard.css"

lines = index_path.read_text(encoding="utf-8").splitlines()

start = next(i for i, line in enumerate(lines) if line.strip() == "<style>")
end = next(i for i, line in enumerate(lines) if line.strip() == "</style>")

css_lines = lines[start + 1 : end]
extra = [
    "",
    ".dashboard-ops-panel-body {",
    "    display: grid;",
    "    gap: 1rem;",
    "}",
    "",
    ".dashboard-ops-wo-kpis {",
    "    display: grid;",
    "    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));",
    "    gap: 0.75rem;",
    "}",
    "",
    ".dashboard-ops-wo-kpi {",
    "    padding: 0.85rem 1rem;",
    "    border-radius: 0.85rem;",
    "    border: 1px solid rgba(148, 163, 184, 0.18);",
    "    background: rgba(248, 250, 252, 0.9);",
    "}",
    "",
    ".dashboard-ops-wo-kpi span {",
    "    display: block;",
    "    font-size: 0.72rem;",
    "    font-weight: 600;",
    "    text-transform: uppercase;",
    "    letter-spacing: 0.04em;",
    "    color: var(--app-text-muted);",
    "}",
    "",
    ".dashboard-ops-wo-kpi strong {",
    "    display: block;",
    "    margin-top: 0.35rem;",
    "    font-size: 1.35rem;",
    "    color: var(--app-text);",
    "}",
    "",
    ".dashboard-ops-empty-note {",
    "    margin: 0;",
    "    padding: 1rem;",
    "    text-align: center;",
    "    color: var(--app-text-muted);",
    "    font-size: 0.875rem;",
    "}",
]

css_path.write_text("\n".join(css_lines + extra) + "\n", encoding="utf-8")

link_block = [
    "<?php $dashboardCssVersion = file_exists(ROOT_PATH . 'assets/css/dashboard.css') ? (string) filemtime(ROOT_PATH . 'assets/css/dashboard.css') : (string) time(); ?>",
    '<link href="<?php echo asset(\'css/dashboard.css\') . \'?v=\' . rawurlencode($dashboardCssVersion); ?>" rel="stylesheet">',
]

new_lines = lines[:start] + link_block + lines[end + 1 :]
index_path.write_text("\n".join(new_lines) + "\n", encoding="utf-8")

print(f"Extracted {len(css_lines)} CSS lines to {css_path}")
