#!/usr/bin/env python3
"""
Readers that turn a festival page into rows, without guessing.

Every reader here is deterministic: the same bytes produce the same rows
forever, and a human can follow why. That is not an aesthetic preference, it is
the rule the wiki asks for — PickiPedia:Artificial Dumb wants structured
content to be "dispassionate, raw, whole-grain data, and not subject to
language inference and stochastic amendments". A lineup assembled by a language
model would read beautifully and could not be checked, and in ten thousand
years nobody will be able to re-run the model to see what it did. A regex
written on a wiki page can be read by anybody, forever, and argued with.

So no reader in this file infers anything. When a page does not say which act
played, these readers say so and stop. The gap becomes a line in a report, and
a person decides.

The kinds, most structured first:

  json-ld     schema.org markup in the page's own <script> tags. Best by a
              distance: the site has already told us what it means, and a
              redesign cannot break it.
  ical        A calendar feed. Unambiguous dates, which is most of the problem.
  html-table  A schedule laid out as a table, with the column meanings given on
              the wiki page rather than guessed from headers.
  html-list   Anything else, read with regexes that live on the wiki.
  pdf-text    Not implemented. Festival schedules are very often a PDF poster,
              so this will be wanted; it is left undone rather than done badly.
"""

import html as html_entities
import json
import re
from datetime import datetime
from html.parser import HTMLParser

# schema.org types that mean "a thing that happened on a stage". Checked
# case-insensitively, and an object may declare several.
EVENT_TYPES = {
    "event", "musicevent", "festival", "musicfestival", "theaterevent",
}

JSON_LD_BLOCK = re.compile(
    r'<script[^>]*\btype\s*=\s*"application/ld\+json"[^>]*>(.*?)</script>',
    re.S | re.I,
)


class ReaderError(Exception):
    """A source could not be read as the kind it claims to be."""


def _as_list(value):
    """schema.org lets almost anything be a scalar or a list. Normalise."""
    if value is None:
        return []
    return value if isinstance(value, list) else [value]


def _name_of(thing):
    """
    The name of a schema.org node, which may be a bare string or an object.

    Returns None rather than str(thing) for shapes we do not recognise: a
    performer rendered as "{'@id': '#x'}" in a lineup is worse than an absent
    one, because it looks like data.
    """
    if isinstance(thing, str):
        return thing.strip() or None
    if isinstance(thing, dict):
        name = thing.get("name")
        if isinstance(name, str) and name.strip():
            return name.strip()
    return None


def _iso_date(value):
    """
    The date part of a schema.org date or datetime, as YYYY-MM-DD.

    Deliberately keeps only the date. Festival start times are published in
    local time with inconsistent offsets, and converting them is how a Friday
    night set ends up on Saturday. The time of day, where we have it, is kept
    separately and as written.
    """
    if not isinstance(value, str) or not value.strip():
        return None
    text = value.strip()
    match = re.match(r"(\d{4})-(\d{2})-(\d{2})", text)
    return match.group(0) if match else None


def _iso_time(value):
    """The time of day of a schema.org datetime, as HH:MM, or None."""
    if not isinstance(value, str):
        return None
    match = re.search(r"\d{4}-\d{2}-\d{2}[T ](\d{2}:\d{2})", value)
    return match.group(1) if match else None


def _walk(node, out):
    """Collect every dict in a nested JSON-LD document, @graph and all."""
    if isinstance(node, dict):
        out.append(node)
        for value in node.values():
            _walk(value, out)
    elif isinstance(node, list):
        for value in node:
            _walk(value, out)


def read_json_ld(html):
    """
    Pull events out of a page's schema.org markup.

    @param html: The page body, decoded.
    @return: list of row dicts: artist, date, start, stage, name, source_kind.
    @raises ReaderError: If the page carries no JSON-LD at all. A page with
        JSON-LD that happens to describe no events is not an error — it is a
        site that does not publish its lineup that way, which the report should
        say plainly rather than dressing up as a failure.
    """
    blocks = JSON_LD_BLOCK.findall(html)
    if not blocks:
        raise ReaderError("no application/ld+json script on the page")

    nodes = []
    for block in blocks:
        try:
            _walk(json.loads(block), nodes)
        except json.JSONDecodeError:
            # One malformed block should not discard the others. Sites embed
            # several, and the broken one is usually an analytics vendor's.
            continue

    rows = []
    for node in nodes:
        types = {str(t).lower() for t in _as_list(node.get("@type"))}
        if not types & EVENT_TYPES:
            continue

        performers = [n for n in (_name_of(p) for p in _as_list(node.get("performer"))) if n]
        name = _name_of(node)

        # An event with performers names them; an event without performers is
        # often itself the act, named in the event name. We record which case
        # it was rather than flattening them, because "the festival said this
        # band played" and "we assumed the event title was a band" are
        # different claims and only one of them is any good.
        for artist in performers or [None]:
            rows.append({
                "artist": artist,
                "event_name": name,
                "date": _iso_date(node.get("startDate")),
                "start": _iso_time(node.get("startDate")),
                "end_date": _iso_date(node.get("endDate")),
                "stage": _name_of(node.get("location")),
                "artist_from": "performer" if artist else None,
                "source_kind": "json-ld",
            })

    return rows


def read_ical(text):
    """
    Pull events out of an iCalendar feed.

    Unfolds continuation lines first, because a long SUMMARY is wrapped at 75
    octets by the spec and every naive parser silently truncates band names
    that way.

    @param text: The .ics body, decoded.
    @return: list of row dicts.
    @raises ReaderError: If there is no VEVENT in it.
    """
    unfolded = re.sub(r"\r?\n[ \t]", "", text)
    blocks = re.findall(r"BEGIN:VEVENT(.*?)END:VEVENT", unfolded, re.S)
    if not blocks:
        raise ReaderError("no VEVENT in the calendar")

    rows = []
    for block in blocks:
        fields = {}
        for line in block.splitlines():
            if ":" not in line:
                continue
            key, _, value = line.partition(":")
            fields[key.split(";")[0].strip().upper()] = value.strip()

        start = fields.get("DTSTART", "")
        date = None
        time = None
        if re.match(r"^\d{8}", start):
            date = f"{start[0:4]}-{start[4:6]}-{start[6:8]}"
            if "T" in start and len(start) >= 15:
                time = f"{start[9:11]}:{start[11:13]}"

        rows.append({
            "artist": None,
            "event_name": fields.get("SUMMARY") or None,
            "date": date,
            "start": time,
            "end_date": None,
            "stage": fields.get("LOCATION") or None,
            "artist_from": None,
            "source_kind": "ical",
        })

    return rows


class _TableParser(HTMLParser):
    """Collects every <table> on a page as a list of rows of cell text."""

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.tables = []
        self._table = None
        self._row = None
        self._cell = None

    def handle_starttag(self, tag, attrs):
        if tag == "table":
            self._table = []
        elif tag == "tr" and self._table is not None:
            self._row = []
        elif tag in ("td", "th") and self._row is not None:
            self._cell = []

    def handle_endtag(self, tag):
        if tag in ("td", "th") and self._cell is not None:
            self._row.append(re.sub(r"\s+", " ", "".join(self._cell)).strip())
            self._cell = None
        elif tag == "tr" and self._row is not None:
            if any(self._row):
                self._table.append(self._row)
            self._row = None
        elif tag == "table" and self._table is not None:
            if self._table:
                self.tables.append(self._table)
            self._table = None

    def handle_data(self, data):
        if self._cell is not None:
            self._cell.append(data)


def read_html_table(html, patterns):
    """
    Read a schedule laid out as an HTML table.

    The column meanings come from the wiki page, not from the table's own
    headers — a line like

        cols: time, artist, stage

    in the page's <pre class="lineup-patterns"> block. Guessing from headers
    works until a festival writes "Who" instead of "Artist", and then it
    produces confidently wrong rows, which is the one outcome worth engineering
    against.

    @param html: The page body, decoded.
    @param patterns: Lines from the source page's pattern block.
    @return: list of row dicts.
    @raises ReaderError: If no cols: line was given, or no table was found.
    """
    spec = None
    for line in patterns:
        if line.lower().startswith("cols:"):
            spec = [c.strip().lower() for c in line.split(":", 1)[1].split(",")]
            break
    if not spec:
        raise ReaderError(
            "html-table needs a 'cols: ...' line in the page's lineup-patterns block"
        )

    parser = _TableParser()
    parser.feed(html)
    if not parser.tables:
        raise ReaderError("no <table> on the page")

    rows = []
    for table in parser.tables:
        for cells in table:
            if len(cells) < len(spec):
                continue
            record = dict(zip(spec, cells))
            # A header row matches the shape of a data row exactly, so it is
            # caught here rather than by position: a row whose artist cell is
            # the word "artist" is the header, in every table anyone has built.
            if (record.get("artist") or "").strip().lower() in spec:
                continue
            rows.append({
                "artist": record.get("artist") or None,
                "event_name": None,
                "date": _normalise_date(record.get("date")),
                "start": _normalise_time(record.get("time") or record.get("start")),
                "end_date": None,
                "stage": record.get("stage") or None,
                "artist_from": "table" if record.get("artist") else None,
                "source_kind": "html-table",
            })
    return rows


def read_html_list(html, patterns):
    """
    Read a lineup with regexes kept on the wiki.

    Each pattern is a Python regex with named groups; artist is the only one
    that means anything on its own, and date, time and stage are used when
    present. A line that matches no pattern is not a failure of the page, it is
    the worklist: it comes back from the caller as unmatched, somebody looks at
    it, and either a pattern gets added or the line was never a set.

    This is the same loop as the podcast title parsers, and it is the part that
    actually earns the name Artificial Dumb: the cleverness lives on a wiki
    page where anybody can read it, not in a model nobody can inspect.

    Two directives may appear among the patterns, and on a real page they are
    usually necessary rather than optional:

        scope-from: <regex>
        scope-to:   <regex>

    They cut the page down to the region the lineup is actually in before any
    pattern runs. Without them, a permissive pattern — and the pattern that
    reads a bare list of band names has to be permissive — matches the
    navigation, the footer, the cookie notice and the newsletter form, and
    produces a lineup featuring "Privacy Policy". This was not a hypothetical:
    the first real page tried against this reader was a list of eighty acts in
    one div on a page of several hundred other lines.

    A scope that matches nothing is an error, not an empty result. A page that
    has been redesigned should stop the run and say so, because the alternative
    is silently writing a shorter lineup every week as the markup drifts.

    @param html: The page body, decoded.
    @param patterns: Regex strings from the page's pattern block.
    @return: (rows, unmatched) — unmatched is a list of text lines.
    @raises ReaderError: If a scope directive matches nothing, or no usable
        pattern was given.
    """
    html = _apply_scope(html, patterns)

    text = re.sub(r"<(script|style)[^>]*>.*?</\1>", " ", html, flags=re.S | re.I)
    text = re.sub(r"<[^>]+>", "\n", text)
    # Entities are decoded after the tags come out, so that a band called
    # "Chucky Waggs & The Company of Raggs" is not written to the wiki as
    # "Chucky Waggs &amp; The Company of Raggs" — which is what the first real
    # run produced.
    text = html_entities.unescape(text)
    lines = [re.sub(r"\s+", " ", line).strip() for line in text.splitlines()]
    lines = [line for line in lines if line]

    compiled = []
    for pattern in patterns:
        if _directive_of(pattern):
            continue
        try:
            compiled.append((pattern, re.compile(pattern)))
        except re.error as e:
            raise ReaderError(f"pattern {pattern!r} does not compile: {e}") from e

    if not compiled:
        raise ReaderError("html-list needs at least one regex in the lineup-patterns block")

    rows, unmatched = [], []
    for line in lines:
        for pattern, regex in compiled:
            match = regex.search(line)
            if not match:
                continue
            group = match.groupdict()
            if not (group.get("artist") or "").strip():
                continue
            rows.append({
                "artist": group["artist"].strip(),
                "event_name": None,
                "date": _normalise_date(group.get("date")),
                "start": _normalise_time(group.get("time")),
                "end_date": None,
                "stage": (group.get("stage") or "").strip() or None,
                "artist_from": "pattern",
                "matched_pattern": pattern,
                "source_kind": "html-list",
            })
            break
        else:
            unmatched.append(line)

    return rows, unmatched


DIRECTIVES = ("cols:", "scope-from:", "scope-to:")


def _directive_of(line):
    """The directive a pattern line is, or None if it is a regex."""
    lowered = line.lower()
    for directive in DIRECTIVES:
        if lowered.startswith(directive):
            return directive
    return None


def _apply_scope(html, patterns):
    """
    Narrow a page to the region named by scope-from / scope-to.

    Either may be given alone: scope-from with no scope-to runs to the end of
    the document, which is what you want for a lineup that is the last thing on
    the page.

    @raises ReaderError: If a given directive matches nothing. Silence here
        would mean reading the whole page with a permissive pattern, which is
        how a scraper starts quietly returning the footer.
    """
    start, end = 0, len(html)

    for line in patterns:
        directive = _directive_of(line)
        if directive not in ("scope-from:", "scope-to:"):
            continue
        expression = line.split(":", 1)[1].strip()
        try:
            regex = re.compile(expression, re.I)
        except re.error as e:
            raise ReaderError(f"{directive} {expression!r} does not compile: {e}") from e

        if directive == "scope-from:":
            match = regex.search(html)
            if not match:
                raise ReaderError(
                    f"scope-from: {expression!r} matched nothing — the page has "
                    "probably been redesigned"
                )
            start = match.end()
        else:
            match = regex.search(html, start)
            if not match:
                raise ReaderError(
                    f"scope-to: {expression!r} matched nothing after scope-from"
                )
            end = match.start()

    return html[start:end]


def _normalise_date(value):
    """
    An ISO date from the handful of forms festivals actually publish.

    Returns None for anything else rather than attempting a best guess. A date
    we are unsure of is worse than a date we do not have, because the page will
    present it with the same confidence as a correct one.
    """
    if not value:
        return None
    text = value.strip()
    for fmt in ("%Y-%m-%d", "%m/%d/%Y", "%m/%d/%y", "%B %d, %Y", "%b %d, %Y",
                "%d %B %Y", "%d %b %Y"):
        try:
            return datetime.strptime(text, fmt).date().isoformat()
        except ValueError:
            continue
    return None


def _normalise_time(value):
    """
    A 24-hour HH:MM from a published set time.

    Stored zero-padded and 24-hour so that it sorts lexically, which is the
    same decision Template:Recurring event made for jams and for the same
    reason. Rendering it as 8:00 pm is the template's business.
    """
    if not value:
        return None
    text = value.strip().lower().replace(".", "")
    match = re.match(r"^(\d{1,2})(?::(\d{2}))?\s*(am|pm)?$", text)
    if not match:
        return None
    hour = int(match.group(1))
    minute = int(match.group(2) or 0)
    meridiem = match.group(3)
    if meridiem == "pm" and hour != 12:
        hour += 12
    elif meridiem == "am" and hour == 12:
        hour = 0
    if not (0 <= hour <= 23 and 0 <= minute <= 59):
        return None
    return f"{hour:02d}:{minute:02d}"


def read(kind, body, patterns=()):
    """
    Dispatch to the reader for a kind.

    @return: (rows, unmatched). Only html-list produces unmatched lines; the
        others return an empty list so that callers have one shape to handle.
    """
    if kind == "json-ld":
        return read_json_ld(body), []
    if kind == "ical":
        return read_ical(body), []
    if kind == "html-table":
        return read_html_table(body, list(patterns)), []
    if kind == "html-list":
        return read_html_list(body, list(patterns))
    if kind == "pdf-text":
        raise ReaderError(
            "pdf-text is not implemented yet; a schedule that only exists as a "
            "PDF poster needs a person for now"
        )
    raise ReaderError(f"no reader for kind {kind!r}")
