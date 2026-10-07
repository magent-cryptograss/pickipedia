#!/usr/bin/env python3
"""
Read a festival's lineup from a source page and report what is there.

This writes nothing. It fetches, reads, and prints — a report for a person, and
on request the wikitext it would produce. Writing is a separate step with its
own account, and keeping it separate is what makes it safe to point this at a
page nobody has looked at before.

The loop it is built for is the podcast parsers' loop, which works:

    run it, read the unmatched list, add a pattern to the wiki, run it again

What is deliberately different is the attitude to time. The podcast parsers
transcribe something that already happened and can take their time being right.
A festival lineup is a claim about next weekend: it can be wrong when published
and can stop being true afterwards. So every row here carries the date it was
read, --diff exists before --write does, and the interesting output of a second
run is not the lineup but what changed since the first.

Usage:

    festival-lineup.py --list
    festival-lineup.py --page "Festival source:Hillberry 2026"
    festival-lineup.py --url https://example.com/lineup --kind json-ld
    festival-lineup.py --url ... --kind json-ld --wikitext --festival Hillberry
"""

import argparse
import re
import sys
from collections import defaultdict
from datetime import date

import festival_config
import festival_readers
from podcast_net import fetch_bytes, UnsafeFeedURL

# Reusing the podcast fetcher rather than writing a second one. The guards it
# carries — refusing anything that resolves off the public internet, capping
# the body size — exist because feed URLs became wiki-editable, and festival
# source URLs are wiki-editable in exactly the same way. The module name is now
# narrower than what it does; renaming it touches the podcast pipeline, so it
# is noted here rather than done in passing.

USER_AGENT = "PickiPedia Festival Lineup/1.0 (https://pickipedia.xyz)"
MAX_PAGE_BYTES = 8 * 1024 * 1024


def fetch(url):
    """Fetch a source page, decoded, with the network guards applied."""
    raw = fetch_bytes(url, USER_AGENT, max_bytes=MAX_PAGE_BYTES)
    # Festival sites are UTF-8 in practice and mojibake in the exceptions;
    # replacing undecodable bytes keeps one bad character from costing a whole
    # lineup, and a mangled band name is visible in the report where a crash
    # would just be absence.
    return raw.decode("utf-8", errors="replace")


def gather(source):
    """
    Fetch and read one source.

    @param source: A record from festival_config.
    @return: (rows, unmatched, error) — error is a string or None. Errors are
        returned rather than raised so that one unreachable site does not end a
        run over a dozen festivals.
    """
    try:
        body = fetch(source["url"])
    except (UnsafeFeedURL, OSError) as e:
        return [], [], f"fetch failed: {e}"

    try:
        rows, unmatched = festival_readers.read(
            source["kind"], body, source.get("patterns", ())
        )
    except festival_readers.ReaderError as e:
        return [], [], str(e)

    checked = date.today().isoformat()
    for row in rows:
        row["checked"] = checked
        row["source_url"] = source["url"]
    return rows, unmatched, None


def report(source, rows, unmatched, error, out=sys.stdout):
    """Print what one source yielded, in the shape a person wants to read."""
    print(f"\n=== {source.get('title') or source['url']}", file=out)
    print(f"    {source['kind']}  {source['url']}", file=out)

    if error:
        print(f"    ERROR: {error}", file=out)
        return

    if not rows:
        print("    no rows — the page did not describe any sets this way", file=out)
    else:
        by_date = defaultdict(list)
        for row in rows:
            by_date[row.get("date") or "(no date)"].append(row)

        named = sum(1 for r in rows if r.get("artist"))
        print(f"    {len(rows)} rows, {named} with an act named", file=out)

        for day in sorted(by_date):
            print(f"    {day}", file=out)
            for row in sorted(by_date[day], key=lambda r: r.get("start") or "~"):
                when = row.get("start") or "  ?  "
                who = row.get("artist") or f"[unnamed] {row.get('event_name') or ''}".strip()
                where = f"  ({row['stage']})" if row.get("stage") else ""
                print(f"      {when}  {who}{where}", file=out)

    if unmatched:
        # The worklist. Capped because an unpatterned page can produce a
        # thousand lines of navigation chrome, and a report nobody reads to the
        # end is a report that does not work.
        print(f"\n    {len(unmatched)} unmatched lines; first 25:", file=out)
        for line in unmatched[:25]:
            print(f"      . {line[:120]}", file=out)


def to_wikitext(rows, festival=None, out=sys.stdout):
    """
    Print the {{Set}} calls these rows would become.

    Note what is absent: no performers= is ever emitted. A listing tells us
    which act was booked, not who stood on the stage, and Template:Set's whole
    reason for existing is that those are different claims. Performers get
    filled in by somebody who was there.
    """
    rows = [r for r in rows if r.get("artist")]
    if not rows:
        print("nothing to write: no row named an act", file=out)
        return

    checked = rows[0].get("checked", date.today().isoformat())
    dates = sorted({r["date"] for r in rows if r.get("date")})
    if dates and festival:
        print(f"{{{{Festival edition|festival={festival}|start={dates[0]}"
              f"|end={dates[-1]}|source={rows[0].get('source_url', '')}"
              f"|checked={checked}}}}}\n", file=out)

    print("== Lineup ==", file=out)
    for row in sorted(rows, key=lambda r: (r.get("date") or "", r.get("start") or "")):
        parts = [f"artist={row['artist']}"]
        if row.get("stage"):
            parts.append(f"stage={row['stage']}")
        if row.get("date"):
            parts.append(f"date={row['date']}")
        if row.get("start"):
            parts.append(f"start={row['start']}")
        print("{{Set|" + "|".join(parts) + "}}", file=out)

    print(f"\n<!-- read from {rows[0].get('source_url', '?')} on {checked} -->", file=out)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[1])
    parser.add_argument("--list", action="store_true",
                        help="list the source pages on the wiki and stop")
    parser.add_argument("--page", help="read one source page by title")
    parser.add_argument("--url", help="read a URL directly, bypassing the wiki")
    parser.add_argument("--kind", default="json-ld",
                        choices=festival_config.KINDS,
                        help="how to read --url (default: json-ld)")
    parser.add_argument("--pattern", action="append", default=[],
                        help="a regex, for trying one out against --url before "
                             "putting it on the wiki; repeatable")
    parser.add_argument("--wikitext", action="store_true",
                        help="print the wikitext these rows would become")
    parser.add_argument("--festival", help="series page name, for --wikitext")
    args = parser.parse_args(argv)

    if args.url:
        sources = [{
            "title": None, "url": args.url, "kind": args.kind,
            "festival": args.festival, "patterns": args.pattern,
        }]
        problems = []
    else:
        sources, problems = festival_config.load_sources()
        if args.page:
            sources = [s for s in sources if s["title"] == args.page]
            if not sources:
                print(f"no usable source page titled {args.page!r}", file=sys.stderr)
                return 1

    for title, reason in problems:
        print(f"skipped {title}: {reason}", file=sys.stderr)

    if args.list:
        for source in sources:
            print(f"{source['kind']:<11} {source['title']}  {source['url']}")
        if not sources:
            print("no source pages yet; add one to Category:Festival sources")
        return 0

    if not sources:
        print("no sources to read; add a page to Category:Festival sources "
              "or pass --url", file=sys.stderr)
        return 1

    all_rows = []
    for source in sources:
        rows, unmatched, error = gather(source)
        all_rows.extend(rows)
        if not args.wikitext:
            report(source, rows, unmatched, error)
        elif error:
            print(f"{source['url']}: {error}", file=sys.stderr)

    if args.wikitext:
        to_wikitext(all_rows, args.festival)

    return 0


if __name__ == "__main__":
    sys.exit(main())
