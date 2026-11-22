#!/usr/bin/env python3
"""One-off migration helper: technical analysis (Markdown, technical-analysis-writer layout) -> `Document` JSON.

The JSON follows config/schema/document.openapi.yaml. This script is NOT an adapter of the tool (adapters never
parse Markdown); it only produces the first test document from the TA of the tool itself.

Usage: python3 scripts/ta_md_to_document.py [--ta docs/ta-writer-json-ta-tool.md] [--out docs/ta-writer.document.json]
                                              [--schema config/schema/document.openapi.yaml] [--no-validate]
"""
import argparse
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
HEADING = re.compile(r"^(#{1,6}) (.*)$")


def parse_headings(lines):
    """Fence-aware heading scan: [(index, level, title)]."""
    out, in_fence = [], False
    for i, line in enumerate(lines):
        if line.startswith("```"):
            in_fence = not in_fence
            continue
        if not in_fence:
            m = HEADING.match(line)
            if m:
                out.append((i, len(m.group(1)), m.group(2).strip()))
    return out


def body(lines, headings, pos, stop_at_any=False):
    """Lines under headings[pos], up to the next heading of level <= its own (or any heading)."""
    start, level, _ = headings[pos]
    end = len(lines)
    for j in range(pos + 1, len(headings)):
        if stop_at_any or headings[j][1] <= level:
            end = headings[j][0]
            break
    return lines[start + 1:end]


def clean(text_lines):
    text = "\n".join(text_lines).strip("\n")
    return re.sub(r"\n{3,}", "\n\n", text).strip()


def extract_mermaid(text):
    """Return (text without mermaid fences, [mermaid sources])."""
    diagrams = []

    def grab(m):
        diagrams.append(m.group(1).rstrip("\n"))
        return ""

    stripped = re.sub(r"```mermaid\n(.*?)\n```\n?", grab, text, flags=re.S)
    return clean(stripped.split("\n")), diagrams


def find(headings, level, title_re):
    for pos, (_, lvl, title) in enumerate(headings):
        if lvl == level and re.match(title_re, title):
            return pos
    raise SystemExit(f"heading not found: {title_re}")


def build(ta_path):
    lines = open(ta_path, encoding="utf-8").read().split("\n")
    h = parse_headings(lines)

    # ---- epic level sections
    context = clean(body(lines, h, find(h, 2, r"^Context$")))
    notes = []
    for title in ("Scope", "Impact analysis", "Feature toggle", "Open points to confirm"):
        notes.append({"type": "note", "content": f"**{title}**\n\n" + clean(body(lines, h, find(h, 2, "^" + re.escape(title) + "$")))})

    # ---- acceptance criteria and coverage
    ac_pos = find(h, 2, r"^Acceptance criteria coverage$")
    criteria, coverage = {}, {}
    for line in body(lines, h, ac_pos):
        m = re.match(r"^\| (AC\d+) — (.*?) \| (.*) \|$", line)
        if not m:
            continue
        ac, text, cell = m.groups()
        criteria[ac] = f"{ac} — {text}"
        for ts, a, b in re.findall(r"TS(\d+) Subtask(\d+)(?:\.\.(\d+))?", cell):
            for n in range(int(a), int(b or a) + 1):
                coverage.setdefault((int(ts), n), []).append(ac)

    # ---- dependencies (to is blocked by from)
    dependencies = []
    for line in body(lines, h, find(h, 2, r"^TS dependencies$")):
        m = re.match(r"^\| (TS\d+) - .*? \| (.*) \|$", line)
        if m and m.group(2).strip() not in ("—", "Blocks"):
            for to in re.split(r"\s*,\s*", m.group(2).strip()):
                dependencies.append({"from": m.group(1), "to": to})

    # ---- overview diagram (before the first technical story)
    ts_section_pos = find(h, 2, r"^Technical stories$")
    first_ts = next(j for j in range(ts_section_pos + 1, len(h)) if h[j][1] == 3)
    overview = "\n".join(lines[h[ts_section_pos][0]:h[first_ts][0]])
    _, epic_diagrams = extract_mermaid(overview)

    # ---- technical stories and subtasks
    technical_stories = []
    for pos, (_, lvl, title) in enumerate(h):
        m = re.match(r"^TA-WRITER TS(\d+) - (.*)$", title)
        if lvl != 3 or not m:
            continue
        ts_no, ts_title = int(m.group(1)), m.group(2)
        subtasks, covered = [], []
        for sp in range(pos + 1, len(h)):
            if h[sp][1] <= 3:
                break
            sm = re.match(r"^TA-WRITER TS(\d+) Subtask(\d+) : (.*)$", h[sp][2])
            if h[sp][1] != 4 or not sm:
                continue
            sub_no = int(sm.group(2))
            sections = {}
            for pp in range(sp + 1, len(h)):
                if h[pp][1] <= 4:
                    break
                if h[pp][1] == 5:
                    sections[h[pp][2]] = body(lines, h, pp, stop_at_any=True)
            how_text, diagrams = extract_mermaid(clean(sections.get("How", [])))
            acs = sorted(set(coverage.get((ts_no, sub_no), [])), key=lambda a: int(a[2:]))
            sub = {
                "title": sm.group(3),
                "reference": f"Subtask#{sub_no}",
                "what": clean(sections.get("What", [])),
                "why": clean(sections.get("Why", [])),
                "where": clean(sections.get("Where", [])),
                "how": how_text,
                "diagrams": [{"type": "mermaid", "content": d} for d in diagrams],
                "resources": [],
            }
            if acs:
                sub["criterion"] = ", ".join(acs)
                covered += [criteria[a] for a in acs if criteria[a] not in covered]
            subtasks.append(sub)
        technical_stories.append({
            "title": ts_title,
            "reference": f"TS{ts_no}",
            "acceptanceCriteriaCovered": covered,
            "resources": [],
            "diagrams": [],
            "subtasks": subtasks,
        })

    return {
        "format": "markdown",
        "version": "1.0.0",
        "structureVersion": "1.0.0",
        "project": {"name": "TA Writer", "reference": "TA-WRITER"},
        "author": {"name": "Laurent De Coninck", "username": "ldeconinck", "email": "laurent.deconinck@alphanetworks.tv"},
        "dependencies": dependencies,
        "epics": [{
            "title": "JSON-driven technical analysis tool",
            "reference": "E1",
            "what": context,
            "resources": notes,
            "diagrams": [{"type": "mermaid", "content": d} for d in epic_diagrams],
            "userStories": [{
                "title": "Derived acceptance criteria of the tool (to confirm)",
                "reference": "US1",
                "acceptanceCriteria": list(criteria.values()),
            }],
            "technicalStories": technical_stories,
        }],
    }


def resolve(node, components):
    """Inline `#/components/schemas/X` references (OpenAPI 3.1 -> plain JSON Schema)."""
    if isinstance(node, dict):
        if "$ref" in node:
            return resolve(components[node["$ref"].rsplit("/", 1)[1]], components)
        return {k: resolve(v, components) for k, v in node.items()}
    if isinstance(node, list):
        return [resolve(v, components) for v in node]
    return node


def validate(document, schema_path):
    import jsonschema
    import yaml
    spec = yaml.safe_load(open(schema_path, encoding="utf-8"))
    schema = resolve(spec["components"]["schemas"]["Document"], spec["components"]["schemas"])
    errors = sorted(jsonschema.Draft7Validator(schema).iter_errors(document), key=lambda e: list(e.path))
    problems = [f"schema: /{'/'.join(map(str, e.path))}: {e.message[:120]}" for e in errors]
    # business checks that the schema cannot express
    epic = document["epics"][0]
    refs = {ts["reference"] for ts in epic["technicalStories"]}
    for d in document["dependencies"]:
        for end in ("from", "to"):
            if d[end] not in refs:
                problems.append(f"dependency {end} {d[end]} is not a technical story")
    known = set(epic["userStories"][0]["acceptanceCriteria"])
    for ts in epic["technicalStories"]:
        for c in ts["acceptanceCriteriaCovered"]:
            if c not in known:
                problems.append(f"{ts['reference']}: unknown criterion {c[:40]}")
    uncovered = known - {c for ts in epic["technicalStories"] for c in ts["acceptanceCriteriaCovered"]}
    problems += [f"uncovered criterion {c[:40]}" for c in sorted(uncovered)]
    return problems


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--ta", default=os.path.join(ROOT, "docs/ta-writer-json-ta-tool.md"))
    ap.add_argument("--out", default=os.path.join(ROOT, "docs/ta-writer.document.json"))
    ap.add_argument("--schema", default=os.path.join(ROOT, "config/schema/document.openapi.yaml"))
    ap.add_argument("--no-validate", action="store_true")
    args = ap.parse_args()
    document = build(args.ta)
    with open(args.out, "w", encoding="utf-8") as fh:
        json.dump(document, fh, ensure_ascii=False, indent=2)
        fh.write("\n")
    epic = document["epics"][0]
    subs = sum(len(ts["subtasks"]) for ts in epic["technicalStories"])
    print(f"wrote {args.out}: {len(epic['technicalStories'])} technical stories, {subs} subtasks, "
          f"{len(document['dependencies'])} dependencies, "
          f"{len(epic['userStories'][0]['acceptanceCriteria'])} criteria, "
          f"{sum(len(s['diagrams']) for ts in epic['technicalStories'] for s in ts['subtasks']) + len(epic['diagrams'])} diagrams")
    if not args.no_validate:
        problems = validate(document, args.schema)
        for p in problems:
            print("PROBLEM", p)
        print("valid" if not problems else f"{len(problems)} problem(s)")
        return 1 if problems else 0
    return 0


if __name__ == "__main__":
    sys.exit(main())
