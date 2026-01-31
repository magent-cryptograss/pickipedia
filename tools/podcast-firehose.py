#!/usr/bin/env python3
"""
Bluegrass Podcast Firehose - Aggregates RSS feeds from bluegrass podcasts
into a single combined feed, sorted by publication date.

Reads feed URLs from a config file, fetches each one, merges all episodes,
and outputs a combined RSS XML file.
"""

import json
import sys
import time
import xml.etree.ElementTree as ET
from datetime import datetime
from email.utils import parsedate_to_datetime, format_datetime
from pathlib import Path
from urllib.request import urlopen, Request
from urllib.error import URLError

FEEDS_CONFIG = Path(__file__).parent / "podcast-feeds.json"
USER_AGENT = "PickiPedia Bluegrass Podcast Firehose/1.0"
FETCH_TIMEOUT = 30
MAX_EPISODES_PER_FEED = 50
MAX_TOTAL_EPISODES = 200


def load_feeds():
    with open(FEEDS_CONFIG) as f:
        return json.load(f)


def fetch_feed(url):
    """Fetch and parse an RSS feed, returning (channel_info, items)."""
    req = Request(url, headers={"User-Agent": USER_AGENT})
    try:
        with urlopen(req, timeout=FETCH_TIMEOUT) as resp:
            raw = resp.read()
    except (URLError, TimeoutError) as e:
        print(f"  WARN: Failed to fetch {url}: {e}", file=sys.stderr)
        return None, []

    try:
        root = ET.fromstring(raw)
    except ET.ParseError as e:
        print(f"  WARN: Failed to parse {url}: {e}", file=sys.stderr)
        return None, []

    channel = root.find("channel")
    if channel is None:
        return None, []

    title_el = channel.find("title")
    link_el = channel.find("link")
    channel_info = {
        "title": title_el.text if title_el is not None else "Unknown",
        "link": link_el.text if link_el is not None else "",
    }

    items = []
    for item in channel.findall("item")[:MAX_EPISODES_PER_FEED]:
        items.append((channel_info, item, raw_pubdate(item)))

    return channel_info, items


def raw_pubdate(item):
    """Extract pubDate as datetime for sorting. Returns epoch 0 on failure."""
    pd = item.find("pubDate")
    if pd is not None and pd.text:
        try:
            return parsedate_to_datetime(pd.text)
        except (ValueError, TypeError):
            pass
    return datetime(1970, 1, 1)


def build_combined_feed(feeds_config, all_items):
    """Build a combined RSS feed XML string."""
    now = format_datetime(datetime.now().astimezone())

    rss = ET.Element("rss", version="2.0")
    rss.set("xmlns:itunes", "http://www.itunes.com/dtds/podcast-1.0.dtd")
    rss.set("xmlns:content", "http://purl.org/rss/1.0/modules/content/")

    channel = ET.SubElement(rss, "channel")
    ET.SubElement(channel, "title").text = "PickiPedia Bluegrass Podcast Firehose"
    ET.SubElement(channel, "link").text = "https://pickipedia.xyz/wiki/Bluegrass_Podcast_Firehose"
    ET.SubElement(channel, "description").text = (
        "A combined feed of bluegrass and traditional music podcasts, "
        "aggregated by PickiPedia. Episodes from multiple shows sorted by date."
    )
    ET.SubElement(channel, "language").text = "en-us"
    ET.SubElement(channel, "lastBuildDate").text = now
    ET.SubElement(channel, "generator").text = "PickiPedia Bluegrass Podcast Firehose"

    # Sort by pubdate descending, take top N
    all_items.sort(key=lambda x: x[2], reverse=True)
    for channel_info, item, pubdate in all_items[:MAX_TOTAL_EPISODES]:
        new_item = ET.SubElement(channel, "item")

        # Copy standard elements
        for tag in ("title", "link", "description", "pubDate", "guid", "enclosure"):
            el = item.find(tag)
            if el is not None:
                new_el = ET.SubElement(new_item, tag)
                new_el.text = el.text
                for k, v in el.attrib.items():
                    new_el.set(k, v)

        # Copy itunes elements
        ns = {"itunes": "http://www.itunes.com/dtds/podcast-1.0.dtd"}
        for itunes_tag in ("duration", "summary", "image", "explicit"):
            el = item.find(f"itunes:{itunes_tag}", ns)
            if el is not None:
                new_el = ET.SubElement(new_item, f"itunes:{itunes_tag}")
                new_el.text = el.text
                for k, v in el.attrib.items():
                    new_el.set(k, v)

        # Prepend podcast name to title
        title_el = new_item.find("title")
        if title_el is not None and title_el.text:
            title_el.text = f"[{channel_info['title']}] {title_el.text}"

        # Add source category
        source_el = ET.SubElement(new_item, "source", url=channel_info.get("link", ""))
        source_el.text = channel_info["title"]

    return rss


def main():
    feeds = load_feeds()
    print(f"Fetching {len(feeds)} feeds...", file=sys.stderr)

    all_items = []
    for feed in feeds:
        name = feed.get("name", feed["url"])
        print(f"  Fetching: {name}...", file=sys.stderr)
        channel_info, items = fetch_feed(feed["url"])
        if items:
            print(f"    Got {len(items)} episodes", file=sys.stderr)
            all_items.extend(items)
        else:
            print(f"    No episodes found", file=sys.stderr)

    print(f"Total episodes: {len(all_items)}", file=sys.stderr)

    rss = build_combined_feed(feeds, all_items)

    # Output
    ET.indent(rss)
    tree = ET.ElementTree(rss)
    output = sys.argv[1] if len(sys.argv) > 1 else None
    if output:
        tree.write(output, encoding="unicode", xml_declaration=True)
        print(f"Written to {output}", file=sys.stderr)
    else:
        print('<?xml version="1.0" encoding="UTF-8"?>')
        ET.dump(rss)


if __name__ == "__main__":
    main()
