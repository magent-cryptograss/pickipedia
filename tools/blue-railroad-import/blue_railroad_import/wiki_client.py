"""Wiki client wrapper for MediaWiki API operations."""

from dataclasses import dataclass
from typing import Optional, Protocol
import mwclient


class WikiClientProtocol(Protocol):
    """Protocol for wiki client operations (for testing)."""

    def get_page_content(self, title: str) -> Optional[str]:
        """Get the current content of a page, or None if it doesn't exist."""
        ...

    def save_page(self, title: str, content: str, summary: str) -> bool:
        """Save content to a page. Returns True if saved, False if unchanged."""
        ...

    def page_exists(self, title: str) -> bool:
        """Check if a page exists."""
        ...


@dataclass
class SaveResult:
    """Result of a page save operation."""
    page_title: str
    action: str  # 'created', 'updated', 'unchanged', 'error'
    message: str = ''


class MWClientWrapper:
    """Wrapper around mwclient for wiki operations."""

    def __init__(self, site_url: str, username: str, password: str):
        # Parse site URL - mwclient wants host without protocol
        if site_url.startswith('https://'):
            host = site_url[8:]
            scheme = 'https'
        elif site_url.startswith('http://'):
            host = site_url[7:]
            scheme = 'http'
        else:
            host = site_url
            scheme = 'https'

        # Remove trailing slash
        host = host.rstrip('/')

        self.site = mwclient.Site(host, scheme=scheme)
        self.site.login(username, password)

    def get_page_content(self, title: str) -> Optional[str]:
        """Get the current content of a page, or None if it doesn't exist."""
        page = self.site.pages[title]
        if page.exists:
            return page.text()
        return None

    def save_page(self, title: str, content: str, summary: str) -> SaveResult:
        """Save content to a page. Checks if content changed first."""
        page = self.site.pages[title]
        existed = page.exists
        current_content = page.text() if existed else None

        # Skip if content unchanged
        if current_content == content:
            return SaveResult(title, 'unchanged', 'Content identical')

        try:
            page.save(content, summary=summary)
            action = 'updated' if existed else 'created'
            return SaveResult(title, action)
        except Exception as e:
            return SaveResult(title, 'error', str(e))

    def page_exists(self, title: str) -> bool:
        """Check if a page exists."""
        return self.site.pages[title].exists


class DryRunClient:
    """Mock client for dry-run mode that doesn't make any changes."""

    def __init__(self, existing_pages: Optional[dict[str, str]] = None):
        self.existing_pages = existing_pages or {}
        self.saved_pages: list[tuple[str, str, str]] = []

    def get_page_content(self, title: str) -> Optional[str]:
        return self.existing_pages.get(title)

    def save_page(self, title: str, content: str, summary: str) -> SaveResult:
        self.saved_pages.append((title, content, summary))

        existed = title in self.existing_pages
        current = self.existing_pages.get(title)

        if current == content:
            return SaveResult(title, 'unchanged', 'Content identical (dry run)')

        action = 'updated' if existed else 'created'
        return SaveResult(title, action, f'{action} (dry run)')

    def page_exists(self, title: str) -> bool:
        return title in self.existing_pages
