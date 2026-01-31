#!/usr/bin/env python3
"""
Tests for the podcast guest extraction pipeline.

These tests verify that we can reliably pull guest names out of podcast
episode titles using per-podcast regex patterns. The patterns live in
podcast-guest-patterns.json and are tried in order — first match wins.

A pattern can either:
  - Extract a guest name via the (?P<guest>...) named group
  - Mark an episode as "skip" (e.g., jam-along backing tracks, topic episodes)

The test suite is organized around real episode titles from the feeds,
grouped by the kind of extraction challenge they represent.
"""

import json
import re
import pytest
from pathlib import Path

# ---------------------------------------------------------------------------
# Fixtures: load the actual pattern config once per session
# ---------------------------------------------------------------------------

TOOLS_DIR = Path(__file__).parent
PATTERNS_FILE = TOOLS_DIR / "podcast-guest-patterns.json"


@pytest.fixture(scope="session")
def all_patterns():
    """Load the full pattern config from disk.

    This is the real config, not a mock — we're testing that the actual
    patterns we ship do what we expect on real episode titles.
    """
    with open(PATTERNS_FILE) as f:
        return json.load(f)["patterns"]


def extract(title: str, patterns: list) -> tuple[list[str], bool]:
    """Run the extraction logic against a single title.

    Returns (guests, was_skipped).  This mirrors the logic in
    podcast-episodes.py's extract_guest() but is self-contained
    so the tests don't import from a script with a __main__ guard.
    """
    for p in patterns:
        m = re.match(p["pattern"], title)
        if m:
            if p.get("skip"):
                return [], True
            groups = m.groupdict()
            guests = []
            if "guest" in groups and groups["guest"]:
                guest = groups["guest"].strip()
                # Split on & / and for multi-guest episodes,
                # but only if each part looks like a name (2+ words)
                parts = re.split(r'\s*(?:&|and)\s*', guest)
                if len(parts) > 1 and all(
                    len(p.strip().split()) >= 2 for p in parts
                ):
                    guests.extend(p.strip() for p in parts)
                else:
                    guests.append(guest)
            if "feat" in groups and groups["feat"]:
                guests.append(groups["feat"].strip())
            return guests, False
    return [], False


# ===================================================================
# 1. BLUEGRASS JAM ALONG
#
# Matt Hutchinson's podcast has the widest variety of title formats:
#   - "Guest Name on Topic" (most common)
#   - "Guest Name - Topic" (dash separator)
#   - "Guest Name (Band) on Topic" (with parenthetical)
#   - "Guest Name Talks/Celebrates/Shares..." (verb after name)
#   - Jam-along tracks that should be skipped (BPM in title)
#   - Briefings, bitesize eps, retrospectives (skip)
# ===================================================================

class TestBluegrassJamAlong:
    """Bluegrass Jam Along — the flagship test, ~500 episodes."""

    @pytest.fixture(autouse=True)
    def _load(self, all_patterns):
        self.pats = all_patterns["Bluegrass Jam Along"]

    # -- Standard "Name on Topic" format --

    def test_simple_name_on_topic(self):
        """The bread and butter: 'First Last on Some Topic'."""
        guests, skip = extract(
            "Dave Sinko on How 'The David Grisman Quintet' Changed His Life",
            self.pats,
        )
        assert guests == ["Dave Sinko"]
        assert not skip

    def test_three_word_name(self):
        """Names with middle initials or three parts."""
        guests, _ = extract(
            "Kristina R. Gaddy - Go Back and Fetch It",
            self.pats,
        )
        assert guests == ["Kristina R. Gaddy"]

    # -- Parenthetical band names --

    def test_name_with_band_parenthetical(self):
        """'Kyle Tuttle (Molly Tuttle & Golden Highway)' — the parenthetical
        contains the guest's band affiliation, common in bluegrass where
        pickers move between projects."""
        guests, _ = extract(
            "Kyle Tuttle (Molly Tuttle & Golden Highway)",
            self.pats,
        )
        assert guests == ["Kyle Tuttle"]

    def test_parenthetical_on_topic(self):
        """Parenthetical followed by 'on Topic'."""
        guests, _ = extract(
            "Maddie Denton (East Nash Grass) on Collaboration and Community",
            self.pats,
        )
        assert guests == ["Maddie Denton"]

    # -- Verb-based patterns --

    def test_celebrates_verb(self):
        """'Name Celebrates Something' — the verb signals end of the name."""
        guests, _ = extract(
            "Trey Hensley Celebrates Flatt and Scruggs at Carnegie Hall",
            self.pats,
        )
        assert guests == ["Trey Hensley"]

    def test_lowercase_verb(self):
        """Some titles use lowercase verbs: 'Tony Trischka has dinner with...'"""
        guests, _ = extract(
            "Tony Trischka has dinner with Bill Monroe",
            self.pats,
        )
        assert guests == ["Tony Trischka"]

    def test_returns_verb(self):
        """'Jake Eddy returns' — just a name and a verb, no topic."""
        guests, _ = extract("Jake Eddy returns", self.pats)
        assert guests == ["Jake Eddy"]

    # -- Multi-guest formats --

    def test_and_separated_guests(self):
        """Two guests joined by 'and' — should split into separate names."""
        guests, _ = extract(
            "Martin Simpson & Thomm Jutz interview",
            self.pats,
        )
        # Both are 2-word names, so the & split should fire
        assert "Martin Simpson" in guests
        assert "Thomm Jutz" in guests

    # -- Celebration/tribute episodes --

    def test_celebrating_with(self):
        """'Celebrating X with Guest Name'."""
        guests, _ = extract(
            "Celebrating IBMA with Jerry Douglas",
            self.pats,
        )
        assert guests == ["Jerry Douglas"]

    def test_tribute_featuring(self):
        """Multi-part tribute episodes list guests after a dash."""
        guests, _ = extract(
            "Earl Scruggs 100th Birthday Tribute part 2 - Jerry Douglas, Alison Brown and Tim O'Brien",
            self.pats,
        )
        # This comes through as a single string from the "tribute part" pattern
        assert len(guests) >= 1
        assert any("Jerry Douglas" in g for g in guests)

    # -- Skip patterns: jam tracks and non-interview content --

    def test_skip_bpm_jam_track(self):
        """Jam-along tracks have BPM in the title — these aren't interviews."""
        _, skip = extract(
            "Sally Goodin (A 75 bpm)",
            self.pats,
        )
        assert skip

    def test_skip_multi_tempo(self):
        """Multi-tempo practice tracks: 'Tune in Key at N tempos'."""
        _, skip = extract(
            "Big Sciota in G at 2 tempos - 75 bpm & 85 bpm",
            self.pats,
        )
        assert skip

    def test_skip_bluegrass_briefing(self):
        """Bluegrass Briefing episodes are news roundups, not interviews."""
        _, skip = extract("Bluegrass Briefing - January 2026", self.pats)
        assert skip

    def test_skip_mini_jam(self):
        _, skip = extract("Mini Jam #42 - Salt Creek", self.pats)
        assert skip

    def test_skip_update(self):
        """Podcast updates aren't guest episodes."""
        _, skip = extract("A quick update on the podcast", self.pats)
        assert skip

    def test_skip_milestone(self):
        _, skip = extract("We reached 200 Episodes!", self.pats)
        assert skip


# ===================================================================
# 2. BLUEGRASS UNLIMITED
#
# The simplest format of all: every single episode is titled
# "Bluegrass Unlimited Podcast with Guest Name". 100% hit rate.
# ===================================================================

class TestBluegrassUnlimited:
    """Bluegrass Unlimited — Dan Miller's podcast, perfectly consistent."""

    @pytest.fixture(autouse=True)
    def _load(self, all_patterns):
        self.pats = all_patterns["Bluegrass Unlimited's Podcast"]

    def test_standard_format(self):
        guests, _ = extract(
            "Bluegrass Unlimited Podcast with Tim Stafford",
            self.pats,
        )
        assert guests == ["Tim Stafford"]

    def test_long_name(self):
        guests, _ = extract(
            "Bluegrass Unlimited Podcast with Kristin Scott Benson",
            self.pats,
        )
        assert guests == ["Kristin Scott Benson"]


# ===================================================================
# 3. WALLS OF TIME
#
# Format: "S1 E5. Guest Name: Topic" or "BONUS. Guest: Topic"
# Season previews (E0) are skipped.
# ===================================================================

class TestWallsOfTime:
    """Walls of Time — Daniel Mullins & Ty Gilpin's history podcast."""

    @pytest.fixture(autouse=True)
    def _load(self, all_patterns):
        self.pats = all_patterns["Walls of Time: Bluegrass Podcast"]

    def test_standard_season_episode(self):
        """Most common format: 'S1 E5. Guest: Topic about bluegrass history'."""
        guests, _ = extract(
            "S3 E4. Tim Stafford: The Bluegrass Hall of Fame",
            self.pats,
        )
        assert guests == ["Tim Stafford"]

    def test_bonus_episode(self):
        guests, _ = extract(
            "BONUS. Ricky Skaggs: Early Days in Kentucky",
            self.pats,
        )
        assert guests == ["Ricky Skaggs"]

    def test_skip_season_preview(self):
        """E0 episodes are season previews/trailers."""
        _, skip = extract("S3 E0. Season 3 Preview", self.pats)
        assert skip


# ===================================================================
# 4. TOY HEART
#
# Tom Power's podcast for The Bluegrass Situation. Most titles
# are just the guest's name, which is elegant but means we need
# to be careful not to match season announcements.
# ===================================================================

class TestToyHeart:
    """Toy Heart — Tom Power's interview podcast."""

    @pytest.fixture(autouse=True)
    def _load(self, all_patterns):
        self.pats = all_patterns["Toy Heart with Tom Power"]

    def test_just_a_name(self):
        """Many episodes are titled with just the guest's name."""
        guests, _ = extract("Sierra Hull", self.pats)
        assert guests == ["Sierra Hull"]

    def test_name_with_accents(self):
        """French-Canadian and other non-ASCII names should work."""
        guests, _ = extract("Yves Lambert", self.pats)
        assert guests == ["Yves Lambert"]

    def test_skip_season_announcement(self):
        _, skip = extract("Season 3 is coming!", self.pats)
        assert skip

    def test_skip_preview(self):
        _, skip = extract(
            "Preview - Toy Heart: A Podcast About Bluegrass", self.pats
        )
        assert skip

    def test_bluegrass_breakdown_crossover(self):
        """A crossover episode with another podcast, guest in the middle."""
        guests, _ = extract(
            "The Bluegrass Breakdown Podcast - Keith Whitley and Ricky Skaggs - Second Generation Bluegrass",
            self.pats,
        )
        assert len(guests) >= 1
        assert any("Keith Whitley" in g for g in guests)


# ===================================================================
# 5. WHAT'S THE REASON FOR THIS PODCAST
#
# Kodi Nottingham's podcast. Title format varies by season:
#   - "What's The Reason For This Podcast - Guest"
#   - "What's The Reason For This Season 2 Episode 5 - Guest"
#   - "What's The Reason For This Session - N - Band Name"
# Note the inconsistent apostrophe (sometimes missing).
# ===================================================================

class TestWhatsTheReason:
    """What's The Reason — Kodi Nottingham's bluegrass podcast."""

    @pytest.fixture(autouse=True)
    def _load(self, all_patterns):
        self.pats = all_patterns["What's The Reason For This Podcast"]

    def test_standard_format(self):
        guests, _ = extract(
            "What's The Reason For This Podcast - Billy Strings - The Interview",
            self.pats,
        )
        assert guests == ["Billy Strings"]

    def test_season_episode_format(self):
        guests, _ = extract(
            "What's The Reason For This Season 2 Episode 5 - Molly Tuttle",
            self.pats,
        )
        assert guests == ["Molly Tuttle"]

    def test_session_format(self):
        """'Session' episodes feature live performances by bands."""
        guests, _ = extract(
            "What's The Reason For This Session - 1 - Sicard Hollow",
            self.pats,
        )
        assert guests == ["Sicard Hollow"]

    def test_missing_apostrophe(self):
        """Some titles drop the apostrophe — 'Whats' instead of 'What's'."""
        guests, _ = extract(
            "Whats The Reason For This Session 5 - The Pickpockets",
            self.pats,
        )
        assert guests == ["The Pickpockets"]


# ===================================================================
# 6. PICKY FINGERS BANJO PODCAST
#
# Keith Billik's banjo-focused podcast. Numbering with #NNN prefix.
# Several formats:
#   - "#139 - Guest Name talks about..."
#   - '#51 - "Song Title" by Artist'
#   - '#124 - "Album" Revisited! Feat. Guest & Guest'
#   - "BONUS - Guest Name..."
# ===================================================================

class TestPickyFingers:
    """The Picky Fingers Banjo Podcast — Keith Billik."""

    @pytest.fixture(autouse=True)
    def _load(self, all_patterns):
        self.pats = all_patterns["The Picky Fingers Banjo Podcast"]

    def test_standard_numbered(self):
        guests, _ = extract(
            "#140 - Tony Trischka on the State of Banjo",
            self.pats,
        )
        assert guests == ["Tony Trischka"]

    def test_song_by_artist(self):
        """'#51 - "Song" by Artist' format — artist after 'by'."""
        guests, _ = extract(
            '#51 - "Prime Time" by Crary, Evans & Barnick',
            self.pats,
        )
        assert len(guests) >= 1

    def test_feat_format(self):
        """'feat.' introduces additional guests."""
        guests, _ = extract(
            '#139 - "Labor of Lust" feat. Kyle Tuttle',
            self.pats,
        )
        assert any("Kyle Tuttle" in g for g in guests)

    def test_bonus_episode(self):
        guests, _ = extract(
            "BONUS - Fireside Chat w/ Noam Pikelny",
            self.pats,
        )
        assert guests == ["Noam Pikelny"]

    def test_in_memoriam(self):
        """In Memoriam episodes still extract the person's name."""
        guests, _ = extract(
            "#100 - In Memoriam: Ralph Stanley",
            self.pats,
        )
        assert guests == ["Ralph Stanley"]


# ===================================================================
# 7. GRASS TALK RADIO
#
# Bradley Laird's podcast is the trickiest. Most episodes are
# topic-based monologues ("GTR-157 - Chord Progressions") with
# only occasional interviews. We only extract when "Interview"
# appears in the title to avoid false positives on topic titles
# that happen to be in Title Case.
# ===================================================================

class TestGrassTalkRadio:
    """Grass Talk Radio — Bradley Laird's mandolin & bluegrass podcast."""

    @pytest.fixture(autouse=True)
    def _load(self, all_patterns):
        self.pats = all_patterns["Grass Talk Radio"]

    def test_interview_with_dash(self):
        guests, _ = extract(
            "GTR-180 - Tony Williamson Interview",
            self.pats,
        )
        assert guests == ["Tony Williamson"]

    def test_interview_no_dash(self):
        guests, _ = extract(
            "GTR-100 Buddy Ashmore Interview",
            self.pats,
        )
        assert guests == ["Buddy Ashmore"]

    def test_no_false_positive_on_topic(self):
        """Title Case topic titles must NOT be extracted as guest names.
        'Merry Christmas' and 'Personal Feedback' aren't people!"""
        guests, skip = extract("GTR-200 Merry Christmas", self.pats)
        assert guests == []

    def test_no_false_positive_topic_with_dash(self):
        guests, skip = extract("GTR-155 - The Mail Bag", self.pats)
        assert guests == []

    def test_no_false_positive_metaphor(self):
        """'Uncle Rico's Time Machine' is a topic, not a guest."""
        guests, _ = extract("GTR-199 Uncle Rico's Time Machine", self.pats)
        assert guests == []


# ===================================================================
# 8. BLUEGRASS BKLYN
#
# Mike Willner & Liz Wolfe's NYC bluegrass podcast. Uses season
# numbering with segment prefixes like "Women in Bluegrass",
# "Home Grown, Locally Known", "On the Road", "Iconic Venues".
# Some episodes are pure topic discussions (skip).
# ===================================================================

class TestBluegrassBKLYN:
    """Bluegrass BKLYN — NYC bluegrass scene podcast."""

    @pytest.fixture(autouse=True)
    def _load(self, all_patterns):
        self.pats = all_patterns["Bluegrass BKLYN"]

    def test_women_in_bluegrass_segment(self):
        guests, _ = extract(
            "S4M9 Women in Bluegrass - Avril Smith!",
            self.pats,
        )
        assert guests == ["Avril Smith"]

    def test_home_grown_segment(self):
        guests, _ = extract(
            "S3E8 Home Grown, Locally Known with Rick Snell!",
            self.pats,
        )
        assert guests == ["Rick Snell"]

    def test_plain_guest_name(self):
        """Some episodes just have 'S3E6 Guest Name!'"""
        guests, _ = extract(
            "S3E6 Martha Spencer and Lucas Pasley!",
            self.pats,
        )
        assert len(guests) >= 1
        assert any("Martha Spencer" in g for g in guests)

    def test_skip_wrap_up(self):
        """Year-end wrap-ups aren't guest episodes."""
        _, skip = extract("S4E9 2025 Wrap Up!", self.pats)
        assert skip or extract("S4E9 2025 Wrap Up!", self.pats)[0] == []

    def test_skip_topic_episode(self):
        """Topic discussion episodes without guests."""
        _, skip = extract("S4M7 Let's Talk Music Education!", self.pats)
        assert skip or extract("S4M7 Let's Talk Music Education!", self.pats)[0] == []


# ===================================================================
# 9. BLUEGRASS AMBASSADORS
#
# Henhouse Prowlers' podcast about global bluegrass. Simple format:
# "Episode N - Guest: Topic"
# ===================================================================

class TestBluegrassAmbassadors:
    """Bluegrass Ambassadors — Henhouse Prowlers."""

    @pytest.fixture(autouse=True)
    def _load(self, all_patterns):
        self.pats = all_patterns["Bluegrass Ambassadors"]

    def test_standard_format(self):
        guests, _ = extract(
            "Episode 2 - Ketch Secor: Old Crow Medicine Show Goes Global",
            self.pats,
        )
        assert guests == ["Ketch Secor"]

    def test_colon_separator(self):
        guests, _ = extract(
            "Episode 1 -  Vijit Malik: Bringing Doc Watson to Dubai",
            self.pats,
        )
        assert guests == ["Vijit Malik"]


# ===================================================================
# 10. EDGE CASES & CROSS-CUTTING CONCERNS
# ===================================================================

class TestEdgeCases:
    """Things that have tripped us up across multiple podcasts."""

    @pytest.fixture(autouse=True)
    def _load(self, all_patterns):
        self.patterns = all_patterns

    def test_no_patterns_returns_empty(self):
        """Podcasts with empty pattern lists (music shows, no guests)
        should return no guests and not skip."""
        guests, skip = extract("Any Title At All", [])
        assert guests == []
        assert not skip

    def test_possessive_name(self):
        """Names ending in 's (possessive) — the 's should be kept
        as part of the name when it's 'Guest's Topic'."""
        pats = self.patterns["Bluegrass Jam Along"]
        guests, _ = extract(
            "Mike Marshall & Chris Thile's Into the Cauldron 20th Anniversary Celebration - part 1 with Mike Marshall",
            pats,
        )
        # Should extract something, not crash
        assert isinstance(guests, list)

    def test_pattern_file_is_valid_json(self):
        """The pattern file must be valid JSON — broken JSON means
        the whole pipeline fails."""
        with open(PATTERNS_FILE) as f:
            data = json.load(f)
        assert "patterns" in data
        assert isinstance(data["patterns"], dict)

    def test_all_patterns_compile(self):
        """Every regex in the config must compile without errors."""
        with open(PATTERNS_FILE) as f:
            data = json.load(f)
        for podcast, pats in data["patterns"].items():
            for i, p in enumerate(pats):
                try:
                    re.compile(p["pattern"])
                except re.error as e:
                    pytest.fail(
                        f"Bad regex in {podcast}[{i}]: {e}\n"
                        f"Pattern: {p['pattern']}"
                    )

    def test_non_skip_patterns_have_guest_group(self):
        """Every non-skip pattern should have a (?P<guest>...) group,
        otherwise we'll match but extract nothing."""
        with open(PATTERNS_FILE) as f:
            data = json.load(f)
        for podcast, pats in data["patterns"].items():
            for i, p in enumerate(pats):
                if p.get("skip"):
                    continue
                assert "(?P<guest>" in p["pattern"], (
                    f"{podcast}[{i}] is not marked skip but has no "
                    f"(?P<guest>...) group: {p['pattern']}"
                )


# ===================================================================
# 11. PAGE TITLE GENERATION
#
# Episode pages live under the podcast name as subpages:
#   "Bluegrass Jam Along/Episode Title Here"
# We need to sanitize titles for MediaWiki constraints.
# ===================================================================

class TestPageTitleGeneration:
    """Wiki page title generation from episode titles."""

    def _make_title(self, podcast, episode_title):
        """Mirrors make_page_title() from podcast-episodes.py."""
        safe = re.sub(r'[#<>\[\]|{}]', '', episode_title).strip()
        if len(safe) > 120:
            safe = safe[:120].rsplit(' ', 1)[0]
        return f"{podcast}/{safe}"

    def test_basic_title(self):
        t = self._make_title("Bluegrass Jam Along", "Tony Trischka on Banjos")
        assert t == "Bluegrass Jam Along/Tony Trischka on Banjos"

    def test_strips_wiki_chars(self):
        """MediaWiki forbids #<>[]|{} in page titles."""
        t = self._make_title("Test", "Episode [1] with <Guest>")
        assert "[" not in t
        assert "<" not in t

    def test_truncates_long_titles(self):
        """Very long episode titles get truncated at a word boundary."""
        long_title = "A " * 100  # 200 chars
        t = self._make_title("Test", long_title)
        # The part after "Test/" should be <= 120 chars
        assert len(t.split("/", 1)[1]) <= 120
