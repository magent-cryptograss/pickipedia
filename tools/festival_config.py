#!/usr/bin/env python3
"""
Festival lineup sources, read from the wiki.

Which pages describe a festival's lineup is knowledge that lives with the
people who go to festivals, not with the people who have push access to this
repository. So the wiki is the source of truth here exactly as it is for
podcasts: each source gets a page in Category:Festival sources carrying a
{{Festival source}} template, and anybody with a wiki account can add one.

What is different from the podcast side, and the reason this is a separate
module rather than a parameter to that one:

A podcast feed transcribes the past. An episode aired; the feed says so; the
worst that happens is we are slow. A festival listing claims the future. It
says Del McCoury Band will play on Friday, and that claim can be wrong when it
is published and can quietly stop being true afterwards — a band drops off, a
day shifts, the whole thing gets rained out. So every record this module
produces carries the date it was read, and nothing downstream is allowed to
present a lineup without it.

There is no fallback JSON here, unlike podcast_config. The firehose publishes
on a timer to real subscribers and must survive the wiki being down; this runs
when somebody runs it, and a wiki that is down simply means try again later.
Inventing a cache would mean writing lineups from a file nobody can see.
"""

import json
import re
from pathlib import Path
from urllib.parse import urlencode
from urllib.request import urlopen, Request

WIKI_API = "https://pickipedia.xyz/api.php"
SOURCE_CATEGORY = "Category:Festival sources"
USER_AGENT = "PickiPedia Festival Config/1.0"
FETCH_TIMEOUT = 30

# The kinds of page a reader knows how to handle, most structured first. The
# order is the order worth trying: a site that emits schema.org JSON-LD has
# already done the parsing for us and cannot be broken by a redesign, which no
# amount of careful HTML scraping can claim.
KINDS = ("json-ld", "ical", "html-table", "html-list", "pdf-text")

# {{Festival source|url=...|kind=...|festival=...}}
#
# Read as template parameters, which is safe here for the same reason it is
# safe for podcast feed URLs: these values are URLs, page titles and short
# keywords, and none of them contain a pipe. Patterns, which do, go in a <pre>
# block below.
SOURCE_TEMPLATE = re.compile(r"\{\{\s*Festival source\s*(\|[^}]*)\}\}", re.S | re.I)

# <pre class="lineup-patterns"> ... </pre>, anywhere on the page.
#
# Same reasoning as the podcast guest patterns, and it was learned the same
# painful way: "|" is syntax to both template parameters and SMW properties, and
# a regex made of alternations is mostly pipes. A pattern containing
# (?:Main Stage|Dance Tent) comes back truncated from either. Raw wikitext in a
# <pre> block survives byte for byte.
PATTERN_BLOCK = re.compile(
    r'<pre[^>]*\bclass\s*=\s*"[^"]*\blineup-patterns\b[^"]*"[^>]*>\n?(.*?)</pre>',
    re.S | re.I,
)


class ConfigError(Exception):
    """A source page does not describe a source we can use."""


def _api(params):
    """GET the MediaWiki API and return parsed JSON."""
    url = f"{WIKI_API}?{urlencode({**params, 'format': 'json'})}"
    req = Request(url, headers={"User-Agent": USER_AGENT})
    with urlopen(req, timeout=FETCH_TIMEOUT) as resp:
        return json.load(resp)


def _template_params(blob):
    """
    Split the captured "|a=1|b=2" of a template call into a dict.

    Values are stripped; empty ones are dropped rather than stored as "", so a
    caller can use a plain `in` test and an editor who leaves a parameter blank
    gets the same result as one who omits it.
    """
    params = {}
    for part in blob.split("|"):
        if "=" not in part:
            continue
        key, _, value = part.partition("=")
        key, value = key.strip().lower(), value.strip()
        if value:
            params[key] = value
    return params


def parse_source_page(title, wikitext):
    """
    Read one Category:Festival sources page into a source record.

    @param title: The wiki page title, kept so a report can say where a bad
        record came from. An error with no page name in it is an error nobody
        can act on.
    @param wikitext: Raw page source.
    @return: dict with title, url, kind, festival (optional) and patterns.
    @raises ConfigError: If the template is missing, or names a kind no reader
        implements — better to refuse loudly than to fetch a page and then
        discover we have nothing to do with it.
    """
    match = SOURCE_TEMPLATE.search(wikitext)
    if not match:
        raise ConfigError(f"{title}: no {{{{Festival source}}}} template on the page")

    params = _template_params(match.group(1))

    url = params.get("url")
    if not url:
        raise ConfigError(f"{title}: {{{{Festival source}}}} has no url=")

    kind = params.get("kind", "json-ld").lower()
    if kind not in KINDS:
        raise ConfigError(
            f"{title}: kind={kind!r} is not one of {', '.join(KINDS)}"
        )

    patterns = []
    block = PATTERN_BLOCK.search(wikitext)
    if block:
        patterns = [
            line.strip()
            for line in block.group(1).splitlines()
            if line.strip() and not line.strip().startswith("#")
        ]

    return {
        "title": title,
        "url": url,
        "kind": kind,
        "festival": params.get("festival"),
        "patterns": patterns,
    }


def load_sources(category=SOURCE_CATEGORY):
    """
    Every usable source page in the category, and every one that isn't.

    Returns both halves rather than raising on the first bad page, because one
    editor's broken template should not stop every other festival from being
    read. The broken ones are a worklist, and the caller is expected to print
    them.

    @return: (sources, problems) where problems is a list of (title, reason).
    """
    listing = _api({
        "action": "query",
        "list": "categorymembers",
        "cmtitle": category,
        "cmlimit": "500",
    })
    titles = [m["title"] for m in listing.get("query", {}).get("categorymembers", [])]
    if not titles:
        return [], []

    sources, problems = [], []

    # The API takes fifty titles at a time; asking for all of them in one go is
    # how you discover that limit in production.
    for chunk in (titles[i:i + 50] for i in range(0, len(titles), 50)):
        pages = _api({
            "action": "query",
            "prop": "revisions",
            "rvprop": "content",
            "rvslots": "main",
            "titles": "|".join(chunk),
        })
        for page in pages.get("query", {}).get("pages", {}).values():
            title = page.get("title", "?")
            try:
                text = page["revisions"][0]["slots"]["main"]["*"]
            except (KeyError, IndexError):
                problems.append((title, "page has no content"))
                continue
            try:
                sources.append(parse_source_page(title, text))
            except ConfigError as e:
                problems.append((title, str(e)))

    return sources, problems
