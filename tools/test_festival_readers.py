#!/usr/bin/env python3
"""
Tests for the festival readers.

The cases here are the ones that cost real lineups elsewhere: an iCal line
folded mid-band-name, a table whose header row looks exactly like a data row, a
JSON-LD performer given as a bare @id, a 12-hour time without a colon. Each one
produces plausible wrong output if handled naively, which is why it is worth a
test rather than a comment.
"""

import unittest

import festival_readers as fr


class TestJsonLd(unittest.TestCase):

    def test_performers_become_rows(self):
        html = """<script type="application/ld+json">
        {"@type": "MusicEvent", "name": "Friday Night",
         "startDate": "2026-10-09T20:00:00-05:00",
         "location": {"@type": "Place", "name": "Main Stage"},
         "performer": [{"@type": "MusicGroup", "name": "The Travelin' McCourys"},
                       {"@type": "MusicGroup", "name": "Sierra Ferrell"}]}
        </script>"""
        rows = fr.read_json_ld(html)
        self.assertEqual([r["artist"] for r in rows],
                         ["The Travelin' McCourys", "Sierra Ferrell"])
        self.assertEqual(rows[0]["date"], "2026-10-09")
        self.assertEqual(rows[0]["start"], "20:00")
        self.assertEqual(rows[0]["stage"], "Main Stage")
        self.assertEqual(rows[0]["artist_from"], "performer")

    def test_performer_without_a_name_is_dropped_not_stringified(self):
        # A reference-only performer is common in real markup. Rendering it as
        # "{'@id': '#x'}" would put a brace-filled non-name on a wiki page.
        html = """<script type="application/ld+json">
        {"@type": "Event", "name": "Set", "startDate": "2026-10-09",
         "performer": [{"@id": "#band-7"}]}
        </script>"""
        rows = fr.read_json_ld(html)
        self.assertEqual(len(rows), 1)
        self.assertIsNone(rows[0]["artist"])
        self.assertEqual(rows[0]["event_name"], "Set")

    def test_graph_is_walked(self):
        html = """<script type="application/ld+json">
        {"@context": "https://schema.org",
         "@graph": [{"@type": "WebPage"},
                    {"@type": "Festival", "name": "Hillberry",
                     "startDate": "2026-10-08", "endDate": "2026-10-11"}]}
        </script>"""
        rows = fr.read_json_ld(html)
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]["end_date"], "2026-10-11")

    def test_one_broken_block_does_not_discard_the_others(self):
        html = ('<script type="application/ld+json">{not json</script>'
                '<script type="application/ld+json">'
                '{"@type":"Event","name":"Good","startDate":"2026-10-09"}</script>')
        self.assertEqual(len(fr.read_json_ld(html)), 1)

    def test_no_json_ld_is_an_error(self):
        with self.assertRaises(fr.ReaderError):
            fr.read_json_ld("<html><body>lineup coming soon</body></html>")


class TestIcal(unittest.TestCase):

    def test_folded_summary_is_unfolded(self):
        # The spec wraps at 75 octets. A parser that reads line by line gets
        # "The Travelin'" and loses the band.
        ics = ("BEGIN:VEVENT\r\n"
               "SUMMARY:The Travelin'\r\n  McCourys\r\n"
               "DTSTART:20261009T200000\r\n"
               "LOCATION:Main Stage\r\n"
               "END:VEVENT\r\n")
        rows = fr.read_ical(ics)
        self.assertEqual(rows[0]["event_name"], "The Travelin' McCourys")
        self.assertEqual(rows[0]["date"], "2026-10-09")
        self.assertEqual(rows[0]["start"], "20:00")

    def test_all_day_event_has_a_date_and_no_time(self):
        ics = "BEGIN:VEVENT\nSUMMARY:Camping opens\nDTSTART;VALUE=DATE:20261008\nEND:VEVENT"
        rows = fr.read_ical(ics)
        self.assertEqual(rows[0]["date"], "2026-10-08")
        self.assertIsNone(rows[0]["start"])

    def test_no_vevent_is_an_error(self):
        with self.assertRaises(fr.ReaderError):
            fr.read_ical("BEGIN:VCALENDAR\nEND:VCALENDAR")


class TestHtmlTable(unittest.TestCase):

    HTML = """<table>
      <tr><th>Time</th><th>Artist</th><th>Stage</th></tr>
      <tr><td>8:00 pm</td><td>Del McCoury Band</td><td>Main</td></tr>
      <tr><td>10:30 pm</td><td>Fruition</td><td>Dance Tent</td></tr>
    </table>"""

    def test_columns_come_from_the_wiki_not_the_headers(self):
        rows = fr.read_html_table(self.HTML, ["cols: time, artist, stage"])
        self.assertEqual([r["artist"] for r in rows], ["Del McCoury Band", "Fruition"])
        self.assertEqual(rows[0]["start"], "20:00")
        self.assertEqual(rows[1]["start"], "22:30")
        self.assertEqual(rows[1]["stage"], "Dance Tent")

    def test_header_row_is_dropped(self):
        rows = fr.read_html_table(self.HTML, ["cols: time, artist, stage"])
        self.assertNotIn("Artist", [r["artist"] for r in rows])

    def test_missing_cols_line_is_an_error(self):
        with self.assertRaises(fr.ReaderError):
            fr.read_html_table(self.HTML, [])


class TestHtmlList(unittest.TestCase):

    HTML = ("<ul><li>8:00 PM - Del McCoury Band (Main Stage)</li>"
            "<li>10:30 PM - Fruition (Dance Tent)</li>"
            "<li>Tickets on sale now</li></ul>")

    PATTERN = (r"^(?P<time>\d{1,2}:\d{2}\s*[APMapm]{2})\s*[-–]\s*"
               r"(?P<artist>[^(]+?)\s*\((?P<stage>[^)]+)\)$")

    def test_matches_become_rows_and_the_rest_become_the_worklist(self):
        rows, unmatched = fr.read_html_list(self.HTML, [self.PATTERN])
        self.assertEqual([r["artist"] for r in rows],
                         ["Del McCoury Band", "Fruition"])
        self.assertEqual(rows[0]["start"], "20:00")
        self.assertIn("Tickets on sale now", unmatched)

    def test_a_pattern_that_does_not_compile_says_so(self):
        with self.assertRaises(fr.ReaderError) as caught:
            fr.read_html_list(self.HTML, ["(?P<artist>"])
        self.assertIn("does not compile", str(caught.exception))

    def test_no_patterns_is_an_error(self):
        with self.assertRaises(fr.ReaderError):
            fr.read_html_list(self.HTML, [])


class TestNormalising(unittest.TestCase):

    def test_times_become_sortable_24_hour(self):
        self.assertEqual(fr._normalise_time("8:00 pm"), "20:00")
        self.assertEqual(fr._normalise_time("12:15 am"), "00:15")
        self.assertEqual(fr._normalise_time("12:15 pm"), "12:15")
        self.assertEqual(fr._normalise_time("9 PM"), "21:00")
        self.assertEqual(fr._normalise_time("14:00"), "14:00")

    def test_an_unreadable_time_is_none_not_a_guess(self):
        self.assertIsNone(fr._normalise_time("late"))
        self.assertIsNone(fr._normalise_time("25:00"))
        self.assertIsNone(fr._normalise_time("after the rain delay"))

    def test_dates_we_know_become_iso_and_the_rest_become_none(self):
        self.assertEqual(fr._normalise_date("October 9, 2026"), "2026-10-09")
        self.assertEqual(fr._normalise_date("10/09/2026"), "2026-10-09")
        self.assertIsNone(fr._normalise_date("Friday"))

    def test_timezone_offsets_do_not_move_the_day(self):
        # A 20:00 set with a -05:00 offset is Friday's set. Converting to UTC
        # would file it on Saturday, which is how a lineup ends up a day out.
        self.assertEqual(fr._iso_date("2026-10-09T20:00:00-05:00"), "2026-10-09")
        self.assertEqual(fr._iso_time("2026-10-09T20:00:00-05:00"), "20:00")


if __name__ == "__main__":
    unittest.main()


class TestScope(unittest.TestCase):
    """
    Scoping is what separates a lineup from a page that contains a lineup.
    Without it the permissive pattern a bare band list requires will happily
    return the navigation.
    """

    PAGE = ("<nav><a>Tickets</a><a>Privacy Policy</a></nav>"
            "<div class='lineupblock'><ul>"
            "<li>Béla Fleck</li><li>Leftover Salmon</li>"
            "</ul></div>"
            "<footer>Newsletter signup</footer>")

    BARE = [r"^(?P<artist>[A-Z][^|]{2,60})$"]

    def test_without_scope_the_chrome_comes_too(self):
        rows, _ = fr.read_html_list(self.PAGE, self.BARE)
        self.assertIn("Privacy Policy", [r["artist"] for r in rows])

    def test_with_scope_only_the_lineup_comes(self):
        patterns = ["scope-from: class='lineupblock'", "scope-to: </div>"] + self.BARE
        rows, _ = fr.read_html_list(self.PAGE, patterns)
        self.assertEqual([r["artist"] for r in rows], ["Béla Fleck", "Leftover Salmon"])

    def test_a_scope_that_matches_nothing_is_an_error(self):
        patterns = ["scope-from: class='lineup-v2'"] + self.BARE
        with self.assertRaises(fr.ReaderError) as caught:
            fr.read_html_list(self.PAGE, patterns)
        self.assertIn("redesigned", str(caught.exception))


class TestEntities(unittest.TestCase):

    def test_ampersands_are_decoded_not_written_through(self):
        # From the first real run: a band name reached the report as
        # "Chucky Waggs &amp; The Company of Raggs".
        html = ("<div class='lineupblock'><ul>"
                "<li>Chucky Waggs &amp; The Company of Raggs</li>"
                "<li>B&#233;la Fleck</li></ul></div>")
        rows, _ = fr.read_html_list(
            html,
            ["scope-from: class='lineupblock'", "scope-to: </ul>",
             r"^(?P<artist>[A-Z][^|]{2,70})$"],
        )
        self.assertEqual([r["artist"] for r in rows],
                         ["Chucky Waggs & The Company of Raggs", "Béla Fleck"])
