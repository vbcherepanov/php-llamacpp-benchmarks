#!/usr/bin/env python3
"""
Plot the four scaling-experiment charts from results/scaling.csv.

Usage:
    python3 bin/plot-scaling.py results/scaling.csv results/charts
"""
from __future__ import annotations

import sys
from pathlib import Path

try:
    import matplotlib
    matplotlib.use("Agg")
    import matplotlib.pyplot as plt
    import pandas as pd
except ImportError as exc:  # pragma: no cover
    print(f"plot-scaling: missing dependency ({exc}).", file=sys.stderr)
    print("Install via:", file=sys.stderr)
    print("  apt-get install -y python3-matplotlib python3-pandas", file=sys.stderr)
    print("or  pip3 install matplotlib pandas", file=sys.stderr)
    sys.exit(0)

GB = 1024 ** 3
RAM_LIMIT_BYTES = 64 * GB

NAIVE_STYLE = dict(color="#c0392b", marker="o", linestyle="--", linewidth=1.6, markersize=6, label="naive")
OPT_STYLE   = dict(color="#1f4ea1", marker="s", linestyle="-",  linewidth=1.8, markersize=6, label="optimized")
OOM_STYLE   = dict(color="#c0392b", marker="x", linestyle="none", markersize=12, markeredgewidth=2.5)
TIMEOUT_STYLE = dict(color="#e67e22", marker="x", linestyle="none", markersize=12, markeredgewidth=2.5)


def annotate_failures(ax, df, x_col, y_value: float | None) -> None:
    """Drop a red ✕ / orange ✕ at failure points, with a label above."""
    for _, row in df.iterrows():
        status = row["status"]
        if status == "ok":
            continue
        y_at = y_value if y_value is not None else 1.0
        style = OOM_STYLE if status == "OOM" else (TIMEOUT_STYLE if status == "TIMEOUT" else None)
        if style is None:
            continue
        ax.plot([row[x_col]], [y_at], **style)
        ax.annotate(status, (row[x_col], y_at), textcoords="offset points", xytext=(0, 10),
                    ha="center", fontsize=9, color=style["color"], fontweight="bold")


def plot_line(ax, sub: pd.DataFrame, x_col: str, y_col: str, style: dict) -> None:
    ok = sub[(sub["status"] == "ok") & sub[y_col].notna() & (sub[y_col] > 0)]
    if not ok.empty:
        ax.plot(ok[x_col], ok[y_col], **style)


def plot_b01(df: pd.DataFrame, out: Path) -> None:
    # Single-plot version: load time only. The PHP-heap subplot was dropped
    # because mmap's heap is 0 by design and can't be drawn on a log scale.
    fig, ax = plt.subplots(figsize=(10, 6), dpi=200)
    fig.suptitle("B01: mmap vs JSON at scale — load time", fontsize=14, fontweight="bold")

    naive = df[df["path"] == "naive"].sort_values("n")
    opt   = df[df["path"] == "optimized"].sort_values("n")

    plot_line(ax, naive, "n", "load_time_ns", NAIVE_STYLE)
    plot_line(ax, opt,   "n", "load_time_ns", OPT_STYLE)

    ax.set_xscale("log")
    ax.set_yscale("log")
    ax.set_xlabel("table entries (log)")
    ax.set_ylabel("load time (ns, log)")
    ax.grid(True, which="both", linestyle=":", alpha=0.6)
    ax.legend(loc="upper left")

    naive_ok = naive[(naive["status"] == "ok") & naive["load_time_ns"].notna()]
    annotate_failures(ax, naive, "n", naive_ok["load_time_ns"].max() if not naive_ok.empty else 1e9)

    fig.tight_layout(rect=(0, 0, 1, 0.95))
    fig.savefig(out)
    plt.close(fig)


def plot_b02(df: pd.DataFrame, out: Path) -> None:
    fig, ax = plt.subplots(figsize=(10, 6), dpi=200)
    fig.suptitle("B02: SplFixedArray vs PHP array — peak memory at scale", fontsize=14, fontweight="bold")

    naive = df[df["path"] == "naive"].sort_values("n")
    opt   = df[df["path"] == "optimized"].sort_values("n")
    plot_line(ax, naive, "n", "peak_memory_bytes", NAIVE_STYLE)
    plot_line(ax, opt,   "n", "peak_memory_bytes", OPT_STYLE)

    ax.set_xscale("log")
    ax.set_yscale("log")
    ax.set_xlabel("elements (log)")
    ax.set_ylabel("peak memory (bytes, log)")
    ax.axhline(RAM_LIMIT_BYTES, color="grey", linestyle=":", linewidth=1.2)
    ax.text(naive["n"].min() if not naive.empty else 1, RAM_LIMIT_BYTES * 1.1, "host RAM 64 GB",
            color="grey", fontsize=9)
    ax.grid(True, which="both", linestyle=":", alpha=0.6)
    ax.legend(loc="upper left")

    naive_ok = naive[(naive["status"] == "ok") & naive["peak_memory_bytes"].notna()]
    annotate_failures(ax, naive, "n", naive_ok["peak_memory_bytes"].max() if not naive_ok.empty else 1e9)

    fig.tight_layout(rect=(0, 0, 1, 0.95))
    fig.savefig(out)
    plt.close(fig)


def plot_b05(df: pd.DataFrame, out: Path) -> None:
    fig, ax = plt.subplots(figsize=(10, 6), dpi=200)
    fig.suptitle("B05: generator vs full-materialised stream — peak memory at scale",
                 fontsize=14, fontweight="bold")

    naive = df[df["path"] == "naive"].sort_values("n")
    opt   = df[df["path"] == "optimized"].sort_values("n")
    plot_line(ax, naive, "n", "peak_memory_bytes", NAIVE_STYLE)
    plot_line(ax, opt,   "n", "peak_memory_bytes", OPT_STYLE)

    ax.set_xscale("log")
    ax.set_yscale("log")
    ax.set_xlabel("records (log)")
    ax.set_ylabel("peak memory (bytes, log)")
    ax.axhline(RAM_LIMIT_BYTES, color="grey", linestyle=":", linewidth=1.2)
    ax.text(naive["n"].min() if not naive.empty else 1, RAM_LIMIT_BYTES * 1.1, "host RAM 64 GB",
            color="grey", fontsize=9)
    ax.grid(True, which="both", linestyle=":", alpha=0.6)
    ax.legend(loc="upper left")

    naive_ok = naive[(naive["status"] == "ok") & naive["peak_memory_bytes"].notna()]
    annotate_failures(ax, naive, "n", naive_ok["peak_memory_bytes"].max() if not naive_ok.empty else 1e9)

    fig.tight_layout(rect=(0, 0, 1, 0.95))
    fig.savefig(out)
    plt.close(fig)


def plot_b06(df: pd.DataFrame, out: Path) -> None:
    fig, ax = plt.subplots(figsize=(10, 6), dpi=200)
    fig.suptitle("B06: column vs row layout — ns per record on single-column scan",
                 fontsize=14, fontweight="bold")

    naive = df[df["path"] == "naive"].sort_values("n")
    opt   = df[df["path"] == "optimized"].sort_values("n")
    plot_line(ax, naive, "n", "extra_metric_value", NAIVE_STYLE)
    plot_line(ax, opt,   "n", "extra_metric_value", OPT_STYLE)

    ax.set_xscale("log")
    # linear Y so the cache-tier ladder is visible
    ax.set_xlabel("records (log)")
    ax.set_ylabel("ns / record (linear) — single-column scan")
    ax.grid(True, which="both", linestyle=":", alpha=0.6)
    ax.legend(loc="upper left")

    naive_ok = naive[(naive["status"] == "ok") & naive["extra_metric_value"].notna()]
    fallback = naive_ok["extra_metric_value"].max() if not naive_ok.empty else 1.0
    annotate_failures(ax, naive, "n", fallback)

    fig.tight_layout(rect=(0, 0, 1, 0.95))
    fig.savefig(out)
    plt.close(fig)


def main(argv: list[str]) -> int:
    if len(argv) < 3:
        print(f"usage: {argv[0]} <csv_path> <charts_dir>", file=sys.stderr)
        return 2

    csv_path = Path(argv[1])
    out_dir  = Path(argv[2])
    out_dir.mkdir(parents=True, exist_ok=True)

    if not csv_path.is_file():
        print(f"csv not found: {csv_path}", file=sys.stderr)
        return 1

    df = pd.read_csv(csv_path)

    # Make numeric columns numeric where possible.
    for col in ("load_time_ns", "peak_memory_bytes", "wall_time_ns", "extra_metric_value", "n"):
        if col in df.columns:
            df[col] = pd.to_numeric(df[col], errors="coerce")

    by_bench = {name: df[df["benchmark"] == name].copy() for name in df["benchmark"].dropna().unique()}

    if "B01" in by_bench:
        plot_b01(by_bench["B01"], out_dir / "scaling-B01.png")
        print(f"wrote {out_dir / 'scaling-B01.png'}")
    if "B02" in by_bench:
        plot_b02(by_bench["B02"], out_dir / "scaling-B02.png")
        print(f"wrote {out_dir / 'scaling-B02.png'}")
    if "B05" in by_bench:
        plot_b05(by_bench["B05"], out_dir / "scaling-B05.png")
        print(f"wrote {out_dir / 'scaling-B05.png'}")
    if "B06" in by_bench:
        plot_b06(by_bench["B06"], out_dir / "scaling-B06.png")
        print(f"wrote {out_dir / 'scaling-B06.png'}")

    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
